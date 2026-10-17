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
 * Access control for the staff assistant — a set of access grants (AWS-security-group style).
 *
 * Each grant names a ROLE or a specific USER, a course scope (all / enrolled / one course) and an
 * optional expiry. Access is a strict allowlist, denied by default: a user may open the assistant only
 * if they are a site admin, or hold an active grant (directly, or via a role they hold). There is no
 * block list. This decides WHO may open the assistant and WHICH courses they are narrowed to; it never
 * widens data visibility — every tool still re-checks the caller's own Moodle capability in context.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_groundedai\local;

/**
 * Staff-assistant access gate, backed by the grants table.
 */
class access_manager {
    /** @var string Grantee kind: a role. */
    const GRANTEE_ROLE = 'role';

    /** @var string Grantee kind: a specific user. */
    const GRANTEE_USER = 'user';

    /** @var string Scope: every course Moodle already grants the grantee. */
    const SCOPE_ALL = 'all';

    /** @var string Scope: only courses the grantee is enrolled in. */
    const SCOPE_ENROLLED = 'enrolled';

    /** @var string Scope: one specific course. */
    const SCOPE_COURSE = 'course';

    /**
     * Role archetypes that may NEVER be granted assistant access. A hard safety invariant: the assistant
     * reads student records, so a learner, guest or bare authenticated user must never reach it. These are
     * excluded from the role picker and from any active grant, and assert_no_forbidden_access() halts the
     * assistant if such a role is ever forced into the grants table.
     *
     * @var string[]
     */
    const FORBIDDEN_ARCHETYPES = ['student', 'guest', 'user', 'frontpage'];

    /** @var string[] The data domains a grant may enable. */
    const DOMAINS = ['courses', 'grades', 'participants', 'dates', 'progress', 'actions'];

    /** @var string[] The sensitive features a grant may enable. */
    const FEATURES = ['email', 'upload', 'export'];

    /** @var \stdClass[]|null Per-request cache of the whole (tiny) grants table. */
    private static ?array $grantsmemo = null;

    /** @var array Per-request cache of each user's active grants (keyed by user id). */
    private static array $usergrantsmemo = [];

    /** @var array Per-request cache of each user's effective course access (keyed by user id). */
    private static array $effmemo = [];

    /**
     * Clear the per-request caches. Per-request in production; tests reset in setUp since statics persist
     * across methods in one PHPUnit process.
     */
    public static function reset_caches(): void {
        self::$grantsmemo = null;
        self::$usergrantsmemo = [];
        self::$effmemo = [];
    }

    /**
     * May this user open the staff assistant? Guests never; site admins always; otherwise only with at
     * least one active grant. Denied by default.
     *
     * @param int $userid The user.
     * @return bool
     */
    public static function can_use(int $userid): bool {
        if (empty($userid) || isguestuser($userid)) {
            return false;
        }
        if (is_siteadmin($userid)) {
            return true;
        }
        return self::active_grants_for_user($userid) !== [];
    }

    /**
     * The user's currently-active grants: user grants naming them, plus role grants on roles they hold in
     * any context. Expired grants and forbidden-role grants are excluded.
     *
     * @param int $userid The user.
     * @return \stdClass[]
     */
    public static function active_grants_for_user(int $userid): array {
        global $DB;
        if (empty($userid) || isguestuser($userid)) {
            return [];
        }
        if (isset(self::$usergrantsmemo[$userid])) {
            return self::$usergrantsmemo[$userid];
        }
        $now = time();
        $forbidden = self::forbidden_role_ids();
        $heldroles = array_map(
            'intval',
            $DB->get_fieldset_select('role_assignments', 'DISTINCT roleid', 'userid = ?', [$userid])
        );
        $out = [];
        foreach (self::all_grants() as $g) {
            if ((int) $g->expiry !== 0 && (int) $g->expiry <= $now) {
                continue;
            }
            if ((string) $g->granteetype === self::GRANTEE_USER) {
                if ((int) $g->userid === $userid) {
                    $out[] = $g;
                }
            } else if ((string) $g->granteetype === self::GRANTEE_ROLE) {
                $rid = (int) $g->roleid;
                if (in_array($rid, $forbidden, true)) {
                    continue;
                }
                if (in_array($rid, $heldroles, true)) {
                    $out[] = $g;
                }
            }
        }
        return self::$usergrantsmemo[$userid] = $out;
    }

    /**
     * The reachable-course descriptor the tools consume, as the union of the user's active grants — the
     * widest active grant wins. Never widens past Moodle (the tool gate still runs can_access_course and
     * the per-context capability check).
     *   ['all' => true]  => unrestricted; defer entirely to Moodle.
     *   ['all' => false, 'enrolled' => bool, 'courseids' => int[]]  => reachable = (enrolments if enrolled)
     *     UNION courseids.
     *
     * @param int $userid The user.
     * @return array{all:bool,enrolled:bool,courseids:int[]}
     */
    public static function effective_course_access(int $userid): array {
        if (is_siteadmin($userid)) {
            return ['all' => true, 'enrolled' => false, 'courseids' => []];
        }
        if (isset(self::$effmemo[$userid])) {
            return self::$effmemo[$userid];
        }
        $grants = self::active_grants_for_user($userid);
        if (!$grants) {
            // Unreachable via can_use anyway; a safe Moodle-bounded default that cannot widen.
            return self::$effmemo[$userid] = ['all' => true, 'enrolled' => false, 'courseids' => []];
        }
        $all = false;
        $enrolled = false;
        $courseids = [];
        foreach ($grants as $g) {
            switch ((string) $g->coursescope) {
                case self::SCOPE_ALL:
                    $all = true;
                    break;
                case self::SCOPE_ENROLLED:
                    $enrolled = true;
                    break;
                case self::SCOPE_COURSE:
                    if ((int) $g->courseid) {
                        $courseids[] = (int) $g->courseid;
                    }
                    break;
            }
        }
        if ($all) {
            return self::$effmemo[$userid] = ['all' => true, 'enrolled' => false, 'courseids' => []];
        }
        return self::$effmemo[$userid] =
            ['all' => false, 'enrolled' => $enrolled, 'courseids' => array_values(array_unique($courseids))];
    }

    /**
     * The data domains the user may reach — the union of their active grants' domains. Admins get all.
     *
     * @param int $userid The user.
     * @return string[]
     */
    public static function enabled_domains(int $userid): array {
        if (is_siteadmin($userid)) {
            return self::DOMAINS;
        }
        $on = [];
        foreach (self::active_grants_for_user($userid) as $g) {
            foreach (explode(',', (string) $g->domains) as $d) {
                $d = trim($d);
                if ($d !== '') {
                    $on[$d] = true;
                }
            }
        }
        return array_values(array_intersect(self::DOMAINS, array_keys($on)));
    }

    /**
     * Whether the user may use a sensitive feature — true if any active grant enables it. Admins always.
     *
     * @param int $userid The user.
     * @param string $feature One of self::FEATURES.
     * @return bool
     */
    public static function feature_enabled(int $userid, string $feature): bool {
        if (is_siteadmin($userid)) {
            return true;
        }
        foreach (self::active_grants_for_user($userid) as $g) {
            if (in_array($feature, explode(',', (string) $g->features), true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The whole (tiny) grants table, read once per request and memoized.
     *
     * @return \stdClass[]
     */
    private static function all_grants(): array {
        global $DB;
        if (self::$grantsmemo === null) {
            self::$grantsmemo = $DB->get_records('local_groundedai_grants');
        }
        return self::$grantsmemo;
    }

    /**
     * The roles an admin MAY grant — every role whose archetype is not forbidden. The only set the role
     * picker offers, so a student, guest, bare-user or front-page role is never selectable.
     *
     * @return array roleid => role record.
     */
    public static function eligible_roles(): array {
        $out = [];
        foreach (get_all_roles() as $id => $role) {
            if (!in_array($role->archetype, self::FORBIDDEN_ARCHETYPES, true)) {
                $out[(int) $id] = $role;
            }
        }
        return $out;
    }

    /**
     * Role ids whose archetype is forbidden (student/guest/user/frontpage).
     *
     * @return int[]
     */
    public static function forbidden_role_ids(): array {
        $ids = [];
        foreach (get_all_roles() as $id => $role) {
            if (in_array($role->archetype, self::FORBIDDEN_ARCHETYPES, true)) {
                $ids[] = (int) $id;
            }
        }
        return $ids;
    }

    /**
     * The hard safety invariant: no role grant may name a forbidden-archetype role. If one is ever present —
     * an accidental code or data edit — the assistant HALTS rather than risk a learner reaching student
     * records. Deliberately ignores expiry, so even a lapsed bad row halts. Called at the entry points.
     *
     * @throws \moodle_exception if a forbidden role has a grant.
     */
    public static function assert_no_forbidden_access(): void {
        global $DB;
        $forbidden = self::forbidden_role_ids();
        if (!$forbidden) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($forbidden, SQL_PARAMS_NAMED, 'f');
        $params['t'] = self::GRANTEE_ROLE;
        if ($DB->record_exists_select('local_groundedai_grants', "granteetype = :t AND roleid $insql", $params)) {
            throw new \moodle_exception('studentaccessdetected', 'local_groundedai');
        }
    }

    /**
     * Add a role grant. The role MUST be eligible (non-forbidden) — rejected otherwise, structurally.
     *
     * @param int $roleid The granted role.
     * @param string $coursescope self::SCOPE_ALL|SCOPE_ENROLLED|SCOPE_COURSE.
     * @param int $courseid The course when scope is SCOPE_COURSE; 0 otherwise.
     * @param int $expiry Absolute unix time the grant expires; 0 = never.
     * @param array|null $domains Data domains this grant enables; null = all.
     * @param array $features Sensitive features this grant enables (subset of self::FEATURES).
     * @return int The new grant id.
     */
    public static function add_role_grant(
        int $roleid,
        string $coursescope,
        int $courseid = 0,
        int $expiry = 0,
        ?array $domains = null,
        array $features = []
    ): int {
        global $DB, $USER;
        if (!array_key_exists($roleid, self::eligible_roles())) {
            throw new \moodle_exception('cannotgrantrole', 'local_groundedai');
        }
        [$coursescope, $courseid] = self::normalize_scope($coursescope, $courseid);
        if (
            $DB->record_exists('local_groundedai_grants', ['granteetype' => self::GRANTEE_ROLE,
                'roleid' => $roleid, 'coursescope' => $coursescope, 'courseid' => $courseid])
        ) {
            throw new \moodle_exception('grantexists', 'local_groundedai');
        }
        $now = time();
        $id = (int) $DB->insert_record('local_groundedai_grants', (object) [
            'granteetype' => self::GRANTEE_ROLE, 'roleid' => $roleid, 'userid' => 0,
            'coursescope' => $coursescope, 'courseid' => $courseid, 'expiry' => max(0, $expiry),
            'domains' => self::clean_domains($domains), 'features' => self::clean_features($features),
            'timecreated' => $now, 'timemodified' => $now, 'usermodified' => (int) $USER->id,
        ]);
        self::reset_caches();
        return $id;
    }

    /**
     * Add a user grant. Names a specific individual, so no archetype check — a user grant is deliberate.
     *
     * @param int $userid The granted user.
     * @param string $coursescope self::SCOPE_ALL|SCOPE_ENROLLED|SCOPE_COURSE.
     * @param int $courseid The course when scope is SCOPE_COURSE; 0 otherwise.
     * @param int $expiry Absolute unix time the grant expires; 0 = never.
     * @return int The new grant id.
     */
    public static function add_user_grant(
        int $userid,
        string $coursescope,
        int $courseid = 0,
        int $expiry = 0,
        ?array $domains = null,
        array $features = []
    ): int {
        global $DB, $USER;
        if ($userid <= 0 || isguestuser($userid) || !$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            throw new \moodle_exception('invaliduser', 'local_groundedai');
        }
        [$coursescope, $courseid] = self::normalize_scope($coursescope, $courseid);
        if (
            $DB->record_exists('local_groundedai_grants', ['granteetype' => self::GRANTEE_USER,
                'userid' => $userid, 'coursescope' => $coursescope, 'courseid' => $courseid])
        ) {
            throw new \moodle_exception('grantexists', 'local_groundedai');
        }
        $now = time();
        $id = (int) $DB->insert_record('local_groundedai_grants', (object) [
            'granteetype' => self::GRANTEE_USER, 'roleid' => 0, 'userid' => $userid,
            'coursescope' => $coursescope, 'courseid' => $courseid, 'expiry' => max(0, $expiry),
            'domains' => self::clean_domains($domains), 'features' => self::clean_features($features),
            'timecreated' => $now, 'timemodified' => $now, 'usermodified' => (int) $USER->id,
        ]);
        self::reset_caches();
        return $id;
    }

    /**
     * Update a grant's scope, course and/or expiry. The grantee identity (type/role/user) is immutable —
     * re-point a grant by delete + add.
     *
     * @param int $grantid The grant.
     * @param array $changes Any of: coursescope, courseid, expiry, domains (array), features (array).
     */
    public static function update_grant(int $grantid, array $changes): void {
        global $DB, $USER;
        if (array_intersect_key($changes, array_flip(['granteetype', 'roleid', 'userid']))) {
            throw new \coding_exception('grant identity is immutable; delete and re-add to re-point a grant');
        }
        $grant = $DB->get_record('local_groundedai_grants', ['id' => $grantid], '*', MUST_EXIST);
        $scope = (string) ($changes['coursescope'] ?? $grant->coursescope);
        $courseid = (int) ($changes['courseid'] ?? $grant->courseid);
        [$scope, $courseid] = self::normalize_scope($scope, $courseid);
        $expiry = array_key_exists('expiry', $changes) ? max(0, (int) $changes['expiry']) : (int) $grant->expiry;
        $domains = array_key_exists('domains', $changes)
            ? self::clean_domains($changes['domains']) : (string) $grant->domains;
        $features = array_key_exists('features', $changes)
            ? self::clean_features((array) $changes['features']) : (string) $grant->features;
        $DB->update_record('local_groundedai_grants', (object) [
            'id' => (int) $grant->id, 'coursescope' => $scope, 'courseid' => $courseid,
            'expiry' => $expiry, 'domains' => $domains, 'features' => $features,
            'timemodified' => time(), 'usermodified' => (int) $USER->id,
        ]);
        self::reset_caches();
    }

    /**
     * Normalise a domains list to a stored CSV: null means all domains; otherwise keep only known keys,
     * in canonical order.
     *
     * @param array|null $domains The requested domains, or null for all.
     * @return string
     */
    private static function clean_domains(?array $domains): string {
        if ($domains === null) {
            return implode(',', self::DOMAINS);
        }
        $keep = array_intersect(self::DOMAINS, array_map('strval', $domains));
        return implode(',', $keep);
    }

    /**
     * Normalise a features list to a stored CSV: keep only known features, in canonical order.
     *
     * @param array $features The requested features.
     * @return string
     */
    private static function clean_features(array $features): string {
        $keep = array_intersect(self::FEATURES, array_map('strval', $features));
        return implode(',', $keep);
    }

    /**
     * Delete a grant.
     *
     * @param int $grantid The grant.
     */
    public static function delete_grant(int $grantid): void {
        global $DB;
        $DB->delete_records('local_groundedai_grants', ['id' => $grantid]);
        self::reset_caches();
    }

    /**
     * Fetch one grant, or null.
     *
     * @param int $grantid The grant.
     * @return \stdClass|null
     */
    public static function get_grant(int $grantid): ?\stdClass {
        global $DB;
        return $DB->get_record('local_groundedai_grants', ['id' => $grantid]) ?: null;
    }

    /**
     * Every grant, decorated for the management table: id, type, grantee (name), scopelabel, coursescope,
     * courseid, expiry, active (not expired), stale (role/user/course gone), timecreated, timemodified.
     * Role and course names are bulk-resolved. Sorted roles-then-users by grantee name.
     *
     * @return \stdClass[]
     */
    public static function list_grants(): array {
        global $DB;
        $grants = self::all_grants();
        if (!$grants) {
            return [];
        }
        $now = time();
        $rolenames = role_get_names(\context_system::instance());
        $eligible = self::eligible_roles();
        $userids = [];
        $courseids = [];
        foreach ($grants as $g) {
            if ((string) $g->granteetype === self::GRANTEE_USER && (int) $g->userid) {
                $userids[(int) $g->userid] = true;
            }
            if ((string) $g->coursescope === self::SCOPE_COURSE && (int) $g->courseid) {
                $courseids[(int) $g->courseid] = true;
            }
        }
        $users = $userids ? $DB->get_records_list('user', 'id', array_keys($userids)) : [];
        $courses = $courseids
            ? $DB->get_records_list('course', 'id', array_keys($courseids), '', 'id, fullname, shortname')
            : [];
        $rows = [];
        foreach ($grants as $g) {
            $type = (string) $g->granteetype;
            $expiry = (int) $g->expiry;
            $stale = false;
            if ($type === self::GRANTEE_ROLE) {
                $rid = (int) $g->roleid;
                $grantee = isset($rolenames[$rid])
                    ? $rolenames[$rid]->localname : get_string('grant_role_none', 'local_groundedai');
                $stale = !isset($eligible[$rid]);
            } else {
                $uid = (int) $g->userid;
                $grantee = isset($users[$uid])
                    ? fullname($users[$uid]) : get_string('grant_user_none', 'local_groundedai');
                $stale = !isset($users[$uid]);
            }
            $scope = (string) $g->coursescope;
            if ($scope === self::SCOPE_COURSE) {
                $cid = (int) $g->courseid;
                if (isset($courses[$cid])) {
                    $scopelabel = format_string($courses[$cid]->fullname);
                } else {
                    $scopelabel = get_string('grant_course_none', 'local_groundedai');
                    $stale = true;
                }
            } else if ($scope === self::SCOPE_ENROLLED) {
                $scopelabel = get_string('scope_enrolled', 'local_groundedai');
            } else {
                $scopelabel = get_string('scope_all', 'local_groundedai');
            }
            $gdomains = array_values(array_filter(explode(',', (string) $g->domains)));
            $gfeatures = array_values(array_filter(explode(',', (string) $g->features)));
            if (!$gdomains) {
                $domainlabel = get_string('grant_domains_none', 'local_groundedai');
            } else if (count($gdomains) === count(self::DOMAINS)) {
                $domainlabel = get_string('grant_domains_all', 'local_groundedai');
            } else {
                $domainlabel = get_string('grant_domains_some', 'local_groundedai', count($gdomains));
            }
            $rows[] = (object) [
                'id' => (int) $g->id,
                'type' => $type,
                'grantee' => $grantee,
                'scopelabel' => $scopelabel,
                'coursescope' => $scope,
                'courseid' => (int) $g->courseid,
                'expiry' => $expiry,
                'domains' => $gdomains,
                'features' => $gfeatures,
                'domainlabel' => $domainlabel,
                'active' => ($expiry === 0 || $expiry > $now),
                'stale' => $stale,
                'timecreated' => (int) $g->timecreated,
                'timemodified' => (int) $g->timemodified,
            ];
        }
        usort($rows, static fn($a, $b) => [$a->type, \core_text::strtolower($a->grantee)]
            <=> [$b->type, \core_text::strtolower($b->grantee)]);
        return $rows;
    }

    /**
     * Normalise a course scope + course id: a course scope needs a real, non-site course; any other scope
     * forces courseid to 0.
     *
     * @param string $coursescope The requested scope.
     * @param int $courseid The requested course id.
     * @return array [string scope, int courseid]
     */
    private static function normalize_scope(string $coursescope, int $courseid): array {
        global $DB;
        if ($coursescope === self::SCOPE_COURSE) {
            if ($courseid <= 0 || $courseid == SITEID || !$DB->record_exists('course', ['id' => $courseid])) {
                throw new \moodle_exception('invalidcourse', 'local_groundedai');
            }
            return [self::SCOPE_COURSE, $courseid];
        }
        $scope = ($coursescope === self::SCOPE_ENROLLED) ? self::SCOPE_ENROLLED : self::SCOPE_ALL;
        return [$scope, 0];
    }
}
