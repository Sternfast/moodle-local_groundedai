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

use local_groundedai\local\compliance_map;
use local_groundedai\local\policy;
use local_groundedai\local\staff\tools;

/**
 * Keeps the access-policy register honest: every policy's enforcement symbol, its proving test(s), and
 * the Moodle capabilities it leans on must still resolve against the LIVE code and Moodle's own
 * registries, and no policy may claim an assurance level it cannot back. This is what lets the register
 * be the single, low-maintenance source of truth — it self-checks on every build and fails loudly the
 * day the code, the tests, or Moodle itself drifts out from under it.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_groundedai\local\policy
 */
final class policy_manifest_test extends \advanced_testcase {
    /** @var string[] Test classes that may hold a policy's proving test. */
    private const TESTCLASSES = [
        readtools_test::class, tools_test::class, conversation_test::class, access_manager_test::class,
        privacy_provider_test::class, staff_assistant_test::class, extractor_test::class, adversarial_test::class,
        auditlog_test::class,
    ];

    /**
     * Every enforcement symbol a policy names still exists in the code.
     */
    public function test_every_enforced_symbol_exists(): void {
        foreach (policy::register() as $p) {
            foreach ($p['enforced_at'] as $symbol) {
                [$class, $method] = explode('::', $symbol, 2);
                $this->assertTrue(
                    method_exists($class, $method),
                    "policy '{$p['id']}': enforced_at '{$symbol}' no longer exists"
                );
            }
        }
    }

    /**
     * Every test a policy cites still exists somewhere in the suite.
     */
    public function test_every_cited_test_exists(): void {
        // Moodle does not autoload tests/, so ensure each sibling test file is included before we probe it
        // (makes this robust whether the file is run alone or as part of the whole suite).
        foreach (self::TESTCLASSES as $tc) {
            $file = __DIR__ . '/' . substr($tc, strrpos($tc, '\\') + 1) . '.php';
            if (is_readable($file)) {
                require_once($file);
            }
        }
        foreach (policy::register() as $p) {
            foreach ($p['verified_by'] as $method) {
                $found = false;
                foreach (self::TESTCLASSES as $tc) {
                    if (method_exists($tc, $method)) {
                        $found = true;
                        break;
                    }
                }
                $this->assertTrue($found, "policy '{$p['id']}': cited test '{$method}' is not in the suite");
            }
        }
    }

    /**
     * Moodle-sync: every capability a policy relies on is still defined by Moodle. If a future Moodle
     * version renames or drops one, this fails with the exact name, pointing at the code that must change.
     */
    public function test_every_capability_still_exists(): void {
        $this->resetAfterTest();
        foreach (policy::capabilities_used() as $cap) {
            $this->assertNotNull(
                get_capability_info($cap),
                "a policy relies on capability '{$cap}', which Moodle no longer defines — update the code and register"
            );
        }
    }

    /**
     * No policy claims an assurance level it cannot back: achieved is never below required, and anything
     * claiming test-proven or higher must cite at least one test.
     */
    public function test_claims_do_not_outrun_evidence(): void {
        foreach (policy::register() as $p) {
            $this->assertGreaterThanOrEqual(
                policy::rank($p['required']),
                policy::rank($p['achieved']),
                "policy '{$p['id']}': achieved {$p['achieved']} is below required {$p['required']}"
            );
            if (policy::rank($p['achieved']) >= policy::rank(policy::A2_TESTED)) {
                $this->assertNotEmpty(
                    $p['verified_by'],
                    "policy '{$p['id']}' claims {$p['achieved']} (test-proven+) but cites no test"
                );
            }
        }
    }

    /**
     * The read-only wall as a manifest fact, derived from the LIVE tool surface so it tracks additions:
     * no registered tool names a write verb, and an unlisted verb is rejected rather than silently run.
     */
    public function test_dispatch_is_a_closed_read_set(): void {
        $this->resetAfterTest();
        \local_groundedai\local\access_manager::reset_caches();
        // An admin reaches every domain and feature, so this enumerates the widest possible tool surface.
        $adminid = (int) get_admin()->id;
        $names = array_map(static fn($s) => $s['function']['name'], tools::schemas(1, $adminid));
        foreach ($names as $name) {
            $this->assertDoesNotMatchRegularExpression(
                '/\b(create|update|delete|insert|save|add|remove|enrol|unenrol|set_|write|edit|grade_|put|post)\w*/',
                $name,
                "registered tool '{$name}' names a write verb"
            );
        }
        $res = tools::dispatch('definitely_not_a_tool_' . random_string(6), [], get_admin()->id, 0);
        $this->assertArrayHasKey('error', $res, 'an unlisted tool verb must be rejected by dispatch');
    }

    /**
     * QM2 — no orphan mappings: every policy id the compliance map cites as evidence resolves to a real
     * policy in the register, so a framework obligation can never point at a control that does not exist.
     * Also asserts each framework entry carries the keys the evidence pack renders.
     */
    public function test_compliance_map_references_resolve(): void {
        $known = [];
        foreach (policy::register() as $p) {
            $known[$p['id']] = true;
        }
        foreach (compliance_map::policy_ids_referenced() as $pid) {
            $this->assertArrayHasKey(
                $pid,
                $known,
                "the compliance map cites policy '{$pid}', which the register does not define"
            );
        }
        foreach (compliance_map::frameworks() as $f) {
            foreach (['id', 'name', 'short', 'authority', 'supports', 'obligations', 'institution_owned'] as $key) {
                $this->assertArrayHasKey($key, $f, "framework '{$f['id']}' is missing the '{$key}' key");
            }
            $this->assertNotEmpty($f['obligations'], "framework '{$f['id']}' has no obligations");
            $this->assertNotEmpty($f['institution_owned'], "framework '{$f['id']}' lists no institution-owned limits");
            foreach ($f['obligations'] as $o) {
                $this->assertArrayHasKey('label', $o);
                $this->assertNotEmpty($o['policies'], "an obligation in '{$f['id']}' cites no policy");
            }
        }
    }
}
