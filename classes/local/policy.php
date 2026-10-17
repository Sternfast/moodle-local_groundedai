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
 * The access-policy register: the single source of truth for the plugin's security contract.
 *
 * Each policy names ONE invariant, the code symbol that enforces it, the test(s) that prove it, the
 * Moodle capabilities it leans on, and its assurance level. It is data only — the compliance evidence
 * pack renders from it, and policy_manifest_test asserts every reference still resolves against the live
 * code and Moodle's own capability registry, so the register cannot drift from the code or outlive a
 * capability Moodle renames. Nothing here enforces anything; it describes where enforcement already lives.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_groundedai\local;

use local_groundedai\local\staff\tools;

/**
 * Access-policy register.
 */
class policy {
    /** @var string Asserted only (in docs/prompt) — a gap to close, never relied upon. */
    const A0_ASSERTED = 'A0';

    /** @var string Enforced in code where the model/client cannot bypass it. */
    const A1_ENFORCED = 'A1';

    /** @var string Enforced AND proven by a unit test, including the negative (blocked) case. */
    const A2_TESTED = 'A2';

    /** @var string Proven by an adversarial test that mounts the real attack. */
    const A3_ADVERSARIAL = 'A3';

    /** @var string Attested externally (dated red-team / signed ACR) for what code cannot prove. */
    const A4_ATTESTED = 'A4';

    /**
     * The assurance ladder in strength order, for comparison.
     *
     * @return string[]
     */
    public static function ladder(): array {
        return [self::A0_ASSERTED, self::A1_ENFORCED, self::A2_TESTED, self::A3_ADVERSARIAL, self::A4_ATTESTED];
    }

    /**
     * The policy register.
     *
     * Keys: id, statement, enforced_at ("Class::method" symbols), verified_by (test method names),
     * caps (Moodle capabilities the policy depends on — checked for existence), required, achieved.
     *
     * Scope of 'caps': CORE Moodle access-control capabilities whose rename/removal would silently
     * weaken a data-visibility gate — those are the ones the meta-test guards. Deliberately NOT listed:
     * the plugin's own capabilities (local/groundedai:*), which are governed by db/access.php; purely
     * cosmetic checks such as moodle/site:viewfullnames in the admin picker; and capabilities passed to
     * get_enrolled_users() as a cohort filter (e.g. mod/assign:submit) rather than used as a gate.
     *
     * @return array[]
     */
    public static function register(): array {
        $tools = tools::class;
        $conv = conversation::class;
        return [
            [
                'id'          => 'student-exclusion',
                'statement'   => 'Students, guests and bare authenticated users can NEVER be granted a role '
                    . 'grant: their roles are not selectable in the role picker, are rejected by the grant '
                    . 'API, never count toward access, and the assistant HALTS outright if a forbidden role '
                    . 'grant is ever forced into the table.',
                'enforced_at' => [access_manager::class . '::assert_no_forbidden_access',
                    access_manager::class . '::eligible_roles', access_manager::class . '::add_role_grant',
                    access_manager::class . '::active_grants_for_user'],
                'verified_by' => ['test_student_role_is_not_eligible', 'test_add_role_grant_rejects_forbidden_role',
                    'test_forbidden_role_grant_halts', 'test_forbidden_role_grant_never_admits'],
                'caps'        => [],
                'required'    => self::A3_ADVERSARIAL,
                'achieved'    => self::A3_ADVERSARIAL,
            ],
            [
                'id'          => 'staff-only',
                'statement'   => 'Access is a strict allowlist, denied by default: only a site admin, or a '
                    . 'holder of an active grant, may open the assistant. There is no block list.',
                'enforced_at' => [access_manager::class . '::can_use', $conv . '::require_access'],
                'verified_by' => ['test_guest_and_empty_are_denied', 'test_no_grants_denies_non_admin',
                    'test_role_grant_admits_holder'],
                'caps'        => [],
                // The cited tests are negative unit tests of can_use(), not an attack through the entry
                // path, so this is honestly test-proven (A2), not adversarial.
                'required'    => self::A2_TESTED,
                'achieved'    => self::A2_TESTED,
            ],
            [
                'id'          => 'read-only-wall',
                'statement'   => 'The assistant exposes no operation that mutates Moodle data; running every '
                    . 'tool, and any write verb a model might emit, changes no record.',
                'enforced_at' => [$tools . '::dispatch'],
                'verified_by' => ['test_readonly_wall_mutates_nothing', 'test_tool_allowlist_is_frozen_read_set',
                    'test_wall_resists_write_verbs_and_injection'],
                'caps'        => [],
                'required'    => self::A2_TESTED,
                'achieved'    => self::A3_ADVERSARIAL,
            ],
            [
                'id'          => 'capability-recheck',
                'statement'   => "Every tool re-checks the caller's own Moodle capability in the item's own "
                    . 'context, so it surfaces only what that user could already see — including the '
                    . 'Q&A-forum and private-reply guards on forum posts.',
                // The re-check lives in the shared gate() AND inline in the grade/forum tools; the cited
                // tests exercise the inline grade checks and a student-actor attack.
                'enforced_at' => [$tools . '::gate', $tools . '::get_grades', $tools . '::get_grade_overview',
                    $tools . '::get_forum_posts'],
                'verified_by' => ['test_grades_require_capability', 'test_student_actor_cannot_read_other_grades'],
                'caps'        => ['moodle/grade:view', 'moodle/grade:viewall', 'moodle/course:viewparticipants',
                    'report/completion:view', 'mod/assign:grade', 'mod/forum:viewdiscussion',
                    'mod/forum:viewqandawithoutposting', 'mod/forum:readprivatereplies',
                    'mod/quiz:viewreports', 'moodle/question:viewall', 'report/log:view'],
                'required'    => self::A2_TESTED,
                'achieved'    => self::A3_ADVERSARIAL,
            ],
            [
                'id'          => 'course-isolation',
                'statement'   => 'A caller cannot read a course they cannot access, even with an inherited capability.',
                'enforced_at' => [$tools . '::gate'],
                'verified_by' => ['test_cross_course_read_is_blocked', 'test_find_people_requires_course_access'],
                'caps'        => [],
                'required'    => self::A2_TESTED,
                'achieved'    => self::A3_ADVERSARIAL,
            ],
            [
                'id'          => 'course-scope-follows-moodle',
                'statement'   => "Course scope never widens past Moodle's own: a grant may be 'all courses' "
                    . "(defer to Moodle), 'enrolled only' (tighten to the grantee's enrolments), or one "
                    . 'specific course. The scope guard runs AFTER Moodle\'s own course-access check, so a '
                    . 'grant can only ever tighten, never grant a course Moodle denies.',
                'enforced_at' => [$tools . '::gate', $tools . '::scope_blocks',
                    access_manager::class . '::effective_course_access'],
                'verified_by' => ['test_enrolled_scope_tightens_to_enrolment', 'test_course_specific_grant_limits_scope'],
                'caps'        => [],
                'required'    => self::A2_TESTED,
                'achieved'    => self::A2_TESTED,
            ],
            [
                'id'          => 'group-isolation',
                'statement'   => 'Under separate groups a caller without accessallgroups sees only their own '
                    . "groups' learners, discussions, events and content.",
                'enforced_at' => [$tools . '::group_restricted_userids'],
                'verified_by' => ['test_get_forum_posts_respects_separate_groups',
                    'test_get_student_groups_respects_separate_groups',
                    'test_read_material_wiki_respects_separate_groups',
                    'test_get_upcoming_respects_separate_groups'],
                'caps'        => ['moodle/site:accessallgroups', 'mod/wiki:managewiki'],
                'required'    => self::A2_TESTED,
                'achieved'    => self::A3_ADVERSARIAL,
            ],
            [
                'id'          => 'contact-pii-optin',
                'statement'   => 'No contact PII (email) is returned unless the admin opts in via participantcontact.',
                'enforced_at' => [$tools . '::find'],
                'verified_by' => ['test_no_contact_email_without_optin', 'test_email_appears_only_with_optin',
                    'test_find_people_returns_no_pii'],
                'caps'        => [],
                'required'    => self::A2_TESTED,
                'achieved'    => self::A3_ADVERSARIAL,
            ],
            [
                'id'          => 'domain-enablement',
                'statement'   => 'A data domain the admin has not enabled is unreachable, regardless of capability.',
                'enforced_at' => [$tools . '::enabled_domains', $tools . '::gate'],
                'verified_by' => ['test_disabled_domain_denies_even_a_teacher'],
                'caps'        => [],
                'required'    => self::A2_TESTED,
                'achieved'    => self::A3_ADVERSARIAL,
            ],
            [
                'id'          => 'output-sanitise',
                'statement'   => 'Assistant output is sanitised: script tags and javascript: links are stripped.',
                'enforced_at' => [$conv . '::with_html'],
                'verified_by' => ['test_assistant_output_is_sanitised'],
                'caps'        => [],
                'required'    => self::A2_TESTED,
                'achieved'    => self::A2_TESTED,
            ],
            [
                'id'          => 'telemetry-metadata-only',
                'statement'   => 'Observability emits only gen_ai.* metadata — never prompt or response content.',
                'enforced_at' => [telemetry::class . '::build_span'],
                'verified_by' => ['test_telemetry_is_metadata_only'],
                'caps'        => [],
                'required'    => self::A2_TESTED,
                'achieved'    => self::A2_TESTED,
            ],
            [
                'id'          => 'byo-key-fail-closed',
                'statement'   => 'Nothing is sent to any LLM until an endpoint is configured; no key or endpoint ships.',
                'enforced_at' => [provider_facade::class . '::chat'],
                'verified_by' => ['test_byo_key_fail_closed'],
                'caps'        => [],
                'required'    => self::A2_TESTED,
                'achieved'    => self::A2_TESTED,
            ],
            [
                'id'          => 'endpoint-access-gating',
                'statement'   => 'Reading conversation history re-checks access, so the kill switch / a revocation '
                    . 'closes the read endpoints too.',
                'enforced_at' => ['\\local_groundedai\\external\\get_messages::execute', $conv . '::require_access'],
                'verified_by' => ['test_read_endpoint_honours_kill_switch'],
                'caps'        => [],
                'required'    => self::A2_TESTED,
                'achieved'    => self::A2_TESTED,
            ],
            [
                'id'          => 'injection-fence',
                'statement'   => 'Student/external-authored tool text is framed as reference data; the read-only, '
                    . 'capability-gated allowlist bounds the blast radius to a manipulated reply.',
                'enforced_at' => [staff_assistant::class . '::answer'],
                'verified_by' => [],
                'caps'        => [],
                // A prompt clause is only an advisory fence (A1); the HARD control is the read-only,
                // capability-gated wall (the read-only-wall / capability-recheck policies at A3), which
                // bounds any injection to a manipulated reply. The authoritative uplift is a dated
                // red-team attestation (A4) — a human artifact, tracked, not yet in hand.
                'required'    => self::A1_ENFORCED,
                'achieved'    => self::A1_ENFORCED,
            ],
            [
                'id'          => 'conversation-ownership',
                'statement'   => 'A user may read, export, modify or delete only their OWN conversations, '
                    . "messages and attachments; another user's are inaccessible even with assistant access.",
                'enforced_at' => [$conv . '::owned', attachment_manager::class . '::owned_conversation',
                    attachment_manager::class . '::owned_attachment', exporter::class . '::prepare'],
                'verified_by' => ['test_ownership_is_enforced'],
                'caps'        => [],
                'required'    => self::A2_TESTED,
                'achieved'    => self::A2_TESTED,
            ],
            [
                'id'          => 'action-link-gating',
                'statement'   => 'An action deep link is emitted only when the caller already holds the edit '
                    . 'capability for that action; the assistant never performs the change and never '
                    . 'fabricates the URL.',
                'enforced_at' => [$tools . '::get_action_link'],
                'verified_by' => ['test_action_link_denies_without_capability',
                    'test_action_link_edit_grade_capability_gate', 'test_action_link_edit_activity_module_context'],
                'caps'        => ['moodle/grade:edit', 'gradereport/singleview:view', 'gradereport/grader:view',
                    'moodle/course:manageactivities', 'moodle/user:viewdetails', 'moodle/course:viewparticipants'],
                'required'    => self::A2_TESTED,
                'achieved'    => self::A2_TESTED,
            ],
            [
                'id'          => 'export-capability',
                'statement'   => 'Producing a downloadable export requires the local/groundedai:export '
                    . 'capability and ownership of the conversation; the file holds only records the user '
                    . 'could already see. This is the ONLY tool that writes — to the plugin\'s own file '
                    . 'area, never to course data.',
                'enforced_at' => [exporter::class . '::prepare'],
                'verified_by' => [],
                // The gate uses the plugin's OWN capability (declared in db/access.php), not a core
                // Moodle one, so it is not in the Moodle-sync caps inventory; its existence is guarded
                // by install/access.php. No dedicated negative test yet, so this is enforced-only (A1).
                'caps'        => [],
                'required'    => self::A1_ENFORCED,
                'achieved'    => self::A1_ENFORCED,
            ],
            [
                'id'          => 'feature-enablement',
                'statement'   => 'File upload and export are each OFF unless the admin enables them; when '
                    . 'off, their tools are never registered and their web-service endpoints reject.',
                'enforced_at' => [$tools . '::schemas'],
                'verified_by' => [],
                'caps'        => [],
                'required'    => self::A1_ENFORCED,
                'achieved'    => self::A1_ENFORCED,
            ],
            [
                'id'          => 'access-audit-log',
                'statement'   => 'Every authorised use of the assistant and every refusal (including a '
                    . 'student-exclusion halt) is written to the standard log as an auditable access trail, '
                    . 'recording the actor and course but never prompt or response content.',
                'enforced_at' => [staff_assistant::class . '::answer', staff_assistant::class . '::log_denied'],
                'verified_by' => ['test_authorised_query_is_logged', 'test_denied_access_is_logged',
                    'test_forbidden_role_halt_is_logged'],
                'caps'        => [],
                'required'    => self::A2_TESTED,
                'achieved'    => self::A2_TESTED,
            ],
            [
                'id'          => 'privacy-erasure',
                'statement'   => 'All stored chat data, attachments, access overrides and generated export '
                    . 'files are declared to the Moodle Privacy API and are exported and erased on a valid '
                    . 'subject request.',
                'enforced_at' => ['\\local_groundedai\\privacy\\provider::export_user_data',
                    '\\local_groundedai\\privacy\\provider::delete_data_for_user',
                    '\\local_groundedai\\privacy\\provider::delete_data_for_all_users_in_context'],
                'verified_by' => ['test_export_and_delete_conversation', 'test_delete_all_in_context'],
                'caps'        => [],
                'required'    => self::A2_TESTED,
                'achieved'    => self::A2_TESTED,
            ],
        ];
    }

    /**
     * Every distinct Moodle capability any policy depends on — the set policy_manifest_test checks exists.
     *
     * @return string[]
     */
    public static function capabilities_used(): array {
        $caps = [];
        foreach (self::register() as $p) {
            foreach ($p['caps'] as $c) {
                $caps[$c] = true;
            }
        }
        ksort($caps);
        return array_keys($caps);
    }

    /**
     * Rank of an assurance level on the ladder (higher = stronger), or -1 if unknown.
     *
     * @param string $level One of the A* constants.
     * @return int
     */
    public static function rank(string $level): int {
        return array_search($level, self::ladder(), true);
    }
}
