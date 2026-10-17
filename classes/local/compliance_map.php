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
 * Compliance map: which of the plugin's access policies supply evidence toward which legal / standards
 * obligations, in the language an education-data lawyer would recognise.
 *
 * This is a DATA file, deliberately framed with care. The plugin never claims to be "compliant" or
 * "certified": compliance is a property of the institution's whole programme, not of a plugin. Each entry
 * states what the plugin SUPPORTS or ENABLES, names the obligation and its authority, points at the
 * policy ids (from policy::register()) that supply the technical evidence, and — just as importantly —
 * lists the institution-owned responsibilities the plugin cannot discharge. policy_manifest_test asserts
 * every policy id named here resolves against the register, so the map cannot drift from the controls.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_groundedai\local;

/**
 * Compliance evidence map.
 */
class compliance_map {
    /**
     * The frameworks and the policy evidence that supports each obligation.
     *
     * Keys per framework: id (slug), name, short (one-line chip), authority (the controlling instrument),
     * supports (what the plugin supports — "supports/enables" language, never "complies"), obligations
     * (each {label, authority?, policies[]}), institution_owned (responsibilities that remain the
     * institution's — the honest limits of what code can do).
     *
     * @return array[]
     */
    public static function frameworks(): array {
        return [
            [
                'id'        => 'ferpa',
                'name'      => 'FERPA',
                'short'     => 'US education records (school-official exception)',
                'authority' => '20 U.S.C. § 1232g; 34 CFR Part 99 (esp. §§ 99.31(a)(1), 99.33, 99.3)',
                'supports'  => 'Supports the FERPA school-official exception: the assistant inherits the '
                    . "institution's own Moodle roles and enrolments (a reasonable method under 34 CFR "
                    . '99.31(a)(1)(ii)), reads education records without mutating them, and keeps record '
                    . 'content inside the institution.',
                'obligations' => [
                    ['label' => 'School-official / outsourcing exception — the vendor performs an '
                        . 'institutional function under its direct control (34 CFR 99.31(a)(1)(i)(B))',
                        'policies' => ['byo-key-fail-closed', 'domain-enablement', 'telemetry-metadata-only',
                            'endpoint-access-gating', 'output-sanitise']],
                    ['label' => 'Legitimate educational interest — disclosure only to officials the '
                        . 'institution has so determined (34 CFR 99.31(a)(1)(i)(A))',
                        'policies' => ['staff-only', 'student-exclusion', 'capability-recheck',
                            'course-scope-follows-moodle']],
                    ['label' => 'Reasonable methods — officials reach only records in which they have that '
                        . 'interest (34 CFR 99.31(a)(1)(ii))',
                        'policies' => ['capability-recheck', 'course-isolation', 'course-scope-follows-moodle',
                            'group-isolation', 'conversation-ownership', 'contact-pii-optin']],
                    ['label' => 'PII identification and data minimisation (34 CFR 99.3)',
                        'policies' => ['contact-pii-optin', 'domain-enablement', 'output-sanitise', 'read-only-wall']],
                    ['label' => 'Records stay maintained by the institution (integrity under its control)',
                        'policies' => ['read-only-wall', 'action-link-gating', 'export-capability']],
                    ['label' => 'Redisclosure control (34 CFR 99.33(a))',
                        'policies' => ['telemetry-metadata-only', 'byo-key-fail-closed', 'injection-fence',
                            'endpoint-access-gating']],
                    ['label' => 'Disposition of any PII the assistant itself stores (inspection rights)',
                        'policies' => ['privacy-erasure', 'conversation-ownership', 'feature-enablement',
                            'export-capability']],
                ],
                'institution_owned' => [
                    'The legitimate-educational-interest determination itself — which roles receive '
                        . 'all-course versus per-course access.',
                    'A written agreement / DPA naming the chosen AI provider a school official under the '
                        . "institution's direct control and binding it to the § 99.33(a) redisclosure limit.",
                    "The annual FERPA notification's criteria for 'school official' and 'legitimate "
                        . "educational interest', covering outsourced parties.",
                    'Directory-information designation and honouring non-disclosure opt-outs (34 CFR 99.3).',
                    'Diligence on the chosen endpoint, provider and region.',
                ],
            ],
            [
                'id'        => 'gdpr',
                'name'      => 'GDPR / UK GDPR',
                'short'     => 'EU / UK data protection',
                'authority' => 'Regulation (EU) 2016/679, Arts 5, 6, 15, 17, 20, 22, 25, 28, 32, Ch. V; '
                    . 'UK GDPR (DPA 2018, as amended by the Data (Use and Access) Act 2025)',
                'supports'  => 'Supports an EU/UK institution by reading only what each staff member already '
                    . 'lawfully accesses, keeping every data domain and egress off by default, and sending '
                    . 'nothing to any model until the institution configures and governs its own endpoint.',
                'obligations' => [
                    ['label' => 'Lawful basis and purpose limitation (Art. 6; Art. 5(1)(a)-(b))',
                        'policies' => ['course-scope-follows-moodle', 'capability-recheck', 'course-isolation',
                            'staff-only']],
                    ['label' => 'Data minimisation (Art. 5(1)(c))',
                        'policies' => ['domain-enablement', 'contact-pii-optin', 'telemetry-metadata-only',
                            'feature-enablement']],
                    ['label' => 'Data protection by design and by default (Art. 25)',
                        'policies' => ['byo-key-fail-closed', 'domain-enablement', 'feature-enablement',
                            'read-only-wall', 'endpoint-access-gating']],
                    ['label' => 'Security of processing; integrity and confidentiality (Art. 32; Art. 5(1)(f))',
                        'policies' => ['staff-only', 'student-exclusion', 'capability-recheck', 'course-isolation',
                            'group-isolation', 'conversation-ownership', 'output-sanitise', 'injection-fence']],
                    ['label' => 'Controller-processor governance (Art. 28)',
                        'policies' => ['telemetry-metadata-only', 'byo-key-fail-closed']],
                    ['label' => 'International transfers (Ch. V, Arts 44-46, 49)',
                        'policies' => ['byo-key-fail-closed', 'telemetry-metadata-only']],
                    ['label' => 'Data-subject rights — access, erasure, portability (Arts 15, 17, 20)',
                        'policies' => ['privacy-erasure', 'export-capability', 'conversation-ownership']],
                    ['label' => 'No solely-automated decisions (Art. 22)',
                        'policies' => ['action-link-gating', 'read-only-wall']],
                ],
                'institution_owned' => [
                    "A written Art. 28(3) data processing agreement with the chosen model provider.",
                    'A valid Chapter V transfer mechanism (adequacy, SCCs/IDTA, or an Art. 49 derogation) '
                        . 'and the region/provider choice.',
                    'Recording the lawful basis (Art. 6), any Art. 9 condition, and a DPIA (Art. 35) where '
                        . 'required.',
                    'Transparency to students (Arts 13-14) that an AI assistant may read their records.',
                    'Fulfilling data-subject requests for personal data held outside the plugin.',
                    'Ensuring staff treat output as advisory with meaningful human judgement (Art. 22).',
                ],
            ],
            [
                'id'        => 'euaiact',
                'name'      => 'EU AI Act',
                'short'     => 'EU AI Act (education high-risk assessment)',
                'authority' => 'Regulation (EU) 2024/1689, Arts 6, 26, 27, 49(2), 50, Ch. V Art. 53; '
                    . 'Annex III(3). High-risk duties apply from 2 Dec 2027; Art. 50 from 2 Aug 2026',
                'supports'  => "Supports an institution's EU AI Act assessment by keeping a staff-only, "
                    . 'read-only assistant out of the Annex III(3) education high-risk functions and '
                    . 'supplying human-oversight, transparency and record-keeping evidence.',
                'obligations' => [
                    ['label' => 'Stays out of Annex III(3) high-risk functions — no admission, grading, '
                        . 'outcome evaluation or proctoring (Art. 6(2))',
                        'policies' => ['read-only-wall', 'action-link-gating', 'course-scope-follows-moodle',
                            'domain-enablement']],
                    ['label' => 'Narrow procedural/preparatory task — the non-high-risk derogation (Art. 6(3))',
                        'policies' => ['read-only-wall', 'action-link-gating', 'capability-recheck',
                            'telemetry-metadata-only']],
                    ['label' => 'Documented non-high-risk assessment and registration (Art. 6(4), 49(2))',
                        'policies' => ['domain-enablement', 'export-capability', 'telemetry-metadata-only',
                            'read-only-wall']],
                    ['label' => 'Deployer use per instructions, under trained human oversight (Art. 26(1)-(2))',
                        'policies' => ['action-link-gating', 'read-only-wall', 'capability-recheck']],
                    ['label' => 'Users know they are interacting with AI (Art. 50(1))',
                        'policies' => ['staff-only', 'output-sanitise']],
                    ['label' => 'Monitor, suspend, and keep deployer-controlled logs (Art. 26(5)-(6))',
                        'policies' => ['telemetry-metadata-only', 'access-audit-log', 'export-capability',
                            'endpoint-access-gating']],
                    ['label' => 'GPAI/foundation-model provider duties (Ch. V Art. 53)',
                        'policies' => ['byo-key-fail-closed', 'telemetry-metadata-only']],
                    ['label' => 'Inputs for a GDPR Art. 35 DPIA (Art. 26(9))',
                        'policies' => ['privacy-erasure', 'contact-pii-optin', 'conversation-ownership',
                            'telemetry-metadata-only']],
                ],
                'institution_owned' => [
                    'The high-risk classification itself — a deployment that admits, grades, evaluates '
                        . 'outcomes or proctors re-enters Annex III(3).',
                    'The Art. 6(4) non-high-risk assessment and its Art. 49(2) registration.',
                    "The chosen endpoint provider's GPAI duties (Art. 53, and Art. 55 systemic-risk).",
                    'Art. 50(2) machine-readable marking of AI-generated output (the model provider).',
                    'A fundamental-rights impact assessment (Art. 27) for public-body deployers.',
                    'Human-oversight staffing and the log-retention / monitoring process (Art. 26).',
                ],
            ],
            [
                'id'        => 'nistairmf',
                'name'      => 'NIST AI RMF',
                'short'     => 'NIST AI RMF 1.0 + Generative AI Profile',
                'authority' => 'NIST AI 100-1 (AI RMF 1.0, Jan 2023) — Govern / Map / Measure / Manage; '
                    . 'NIST-AI-600-1 Generative AI Profile',
                'supports'  => "Supports an institution's NIST AI RMF programme: the access-control, "
                    . 'human-oversight, privacy, provenance, security and logging policies supply evidence '
                    . 'across the Govern, Map, Measure and Manage functions.',
                'obligations' => [
                    ['label' => 'Document who may use the system; restrict each user to role-permitted data '
                        . '(Govern 1.1 / 2.1, Map 1.1)',
                        'policies' => ['staff-only', 'student-exclusion', 'capability-recheck', 'course-isolation',
                            'course-scope-follows-moodle', 'group-isolation', 'conversation-ownership',
                            'domain-enablement']],
                    ['label' => 'Human-AI roles and oversight; no autonomous action on records '
                        . '(Govern 3.2, Map 3.5)',
                        'policies' => ['read-only-wall', 'action-link-gating']],
                    ['label' => 'Examine and minimise privacy risk / PII exposure (Measure 2.10; GenAI §3)',
                        'policies' => ['contact-pii-optin', 'conversation-ownership', 'privacy-erasure',
                            'telemetry-metadata-only', 'output-sanitise']],
                    ['label' => 'Security / resilience with disable and incident controls (Measure 2.7, '
                        . 'Manage 4.1; GenAI Info-Security)',
                        'policies' => ['byo-key-fail-closed', 'endpoint-access-gating', 'output-sanitise',
                            'read-only-wall']],
                    ['label' => 'Keep output grounded and bound untrusted content (Measure 2.9; GenAI '
                        . 'Info-Integrity)',
                        'policies' => ['injection-fence', 'read-only-wall', 'capability-recheck']],
                    ['label' => 'Manage third-party (model-provider) risk (Govern 6.1/6.2, Map 4.1)',
                        'policies' => ['byo-key-fail-closed', 'telemetry-metadata-only', 'domain-enablement']],
                    ['label' => 'AI-system inventory and an auditable access/enablement trail '
                        . '(Govern 1.6, Measure 2.8)',
                        'policies' => ['access-audit-log', 'telemetry-metadata-only', 'endpoint-access-gating',
                            'domain-enablement', 'feature-enablement']],
                ],
                'institution_owned' => [
                    'A signed DPA and region/provider choice for the chosen LLM endpoint.',
                    'The AI-system inventory entry and documented intended-use / risk assessment.',
                    'A dated red-team / adversarial attestation for prompt-injection and GAI content risk.',
                    'The written human-oversight policy, role definitions and escalation procedure.',
                    'The post-deployment monitoring plan and incident-response process.',
                    'Bias / fairness / safety evaluation of the chosen BYO model.',
                ],
            ],
            [
                'id'        => 'iso42001',
                'name'      => 'ISO/IEC 42001 & 27001',
                'short'     => 'AI management system + information security',
                'authority' => 'ISO/IEC 42001:2023 (Annex A — A.6.2.8, A.9.2, A.9.4, A.7.4); '
                    . 'ISO/IEC 27001:2022 (Annex A — A.5.15, A.8.3, A.8.12, A.8.15, A.8.16)',
                'supports'  => 'Supports the access-control, logging, data-minimisation, controlled-transfer '
                    . 'and human-oversight controls of ISO/IEC 42001:2023 and 27001:2022 that a read-only '
                    . 'assistant can enforce in code.',
                'obligations' => [
                    ['label' => 'Access control and privileged access (27001 A.5.15, A.8.2)',
                        'policies' => ['staff-only', 'student-exclusion', 'capability-recheck',
                            'endpoint-access-gating']],
                    ['label' => 'Access rights provisioned, reviewed, revoked (27001 A.5.18)',
                        'policies' => ['course-scope-follows-moodle', 'capability-recheck']],
                    ['label' => 'Information access restriction — course/group/record isolation (27001 A.8.3)',
                        'policies' => ['course-isolation', 'group-isolation', 'conversation-ownership',
                            'domain-enablement']],
                    ['label' => 'Privacy / PII protection and masking (27001 A.5.34, A.8.11)',
                        'policies' => ['contact-pii-optin', 'conversation-ownership']],
                    ['label' => 'Information deletion on request (27001 A.8.10)',
                        'policies' => ['privacy-erasure']],
                    ['label' => 'Data-leakage prevention and controlled transfer (27001 A.8.12, A.5.14)',
                        'policies' => ['read-only-wall', 'byo-key-fail-closed', 'telemetry-metadata-only',
                            'output-sanitise']],
                    ['label' => 'Logging and event logging (27001 A.8.15; 42001 A.6.2.8)',
                        'policies' => ['access-audit-log', 'export-capability', 'telemetry-metadata-only']],
                    ['label' => 'Monitoring activities and AI-system operation (27001 A.8.16; 42001 A.6.2.6)',
                        'policies' => ['endpoint-access-gating', 'telemetry-metadata-only']],
                    ['label' => 'Secure coding / safe handling of untrusted input (27001 A.8.26, A.8.28)',
                        'policies' => ['injection-fence', 'output-sanitise']],
                    ['label' => 'Responsible use — human oversight, not autonomous (42001 A.9.2)',
                        'policies' => ['read-only-wall', 'action-link-gating']],
                    ['label' => 'Stays within declared intended use and minimises data (42001 A.9.4, A.7.4)',
                        'policies' => ['domain-enablement', 'feature-enablement', 'contact-pii-optin']],
                ],
                'institution_owned' => [
                    'Establishing and operating the AI management system itself (42001 Clauses 4-10, SoA).',
                    'The AI-system impact assessment (42001 A.5.2/A.5.4/A.5.5).',
                    "Supplier/cloud controls and the provider's own 42001/27001 posture and DPA.",
                    'Data-residency / region selection for the configured endpoint.',
                    'Defining which Moodle roles receive all-course versus per-course access.',
                    'Clause 6 risk assessment, internal audit and management review.',
                    'Quality and governance of the grounding corpus (its own course content).',
                ],
            ],
            [
                'id'        => 'studentprivacy',
                'name'      => 'COPPA / SOPIPA / NDPA',
                'short'     => 'US state student-data-privacy regime',
                'authority' => 'COPPA, 15 U.S.C. §§ 6501-6506 & 16 CFR Part 312 (2025 rule); California '
                    . 'SOPIPA § 22584; Student Privacy Pledge; SDPC National Data Privacy Agreement',
                'supports'  => "Supports an institution's COPPA, SOPIPA, Student Privacy Pledge and SDPC NDPA "
                    . 'obligations: a staff-only, read-only assistant that egresses only gen_ai.* metadata '
                    . 'to the institution-chosen endpoint and makes stored student data erasable.',
                'obligations' => [
                    ['label' => 'No targeted advertising, no sale, no non-educational profiling '
                        . '(SOPIPA § 22584(b)(1)-(3))',
                        'policies' => ['telemetry-metadata-only', 'byo-key-fail-closed', 'output-sanitise']],
                    ['label' => 'Use student PII only for the authorised educational purpose, minimised '
                        . '(SOPIPA § 22584(b)(4); NDPA § 1.1)',
                        'policies' => ['domain-enablement', 'contact-pii-optin', 'feature-enablement',
                            'staff-only', 'student-exclusion', 'read-only-wall']],
                    ['label' => 'Reasonable security; access limited to authorised persons '
                        . '(COPPA § 312.8; SOPIPA § 22584(d)(1); NDPA Art. 4)',
                        'policies' => ['capability-recheck', 'course-isolation', 'course-scope-follows-moodle',
                            'group-isolation', 'conversation-ownership', 'staff-only', 'student-exclusion',
                            'injection-fence', 'output-sanitise']],
                    ['label' => 'Disclose and bind each subprocessor (NDPA §§ 2.3, 4.4.1.4; COPPA § 312.8(c))',
                        'policies' => ['byo-key-fail-closed', 'endpoint-access-gating', 'telemetry-metadata-only']],
                    ['label' => 'Retain no longer than necessary; delete on request (COPPA § 312.10; '
                        . 'SOPIPA § 22584(d)(2))',
                        'policies' => ['privacy-erasure', 'conversation-ownership', 'export-capability']],
                    ['label' => 'Student data stays the LEA\'s property, within its control (NDPA §§ 2.1, 4.4)',
                        'policies' => ['byo-key-fail-closed', 'endpoint-access-gating', 'read-only-wall',
                            'action-link-gating', 'telemetry-metadata-only', 'injection-fence']],
                ],
                'institution_owned' => [
                    'A signed DPA / subprocessor agreement (e.g. an SDPC NDPA) with the chosen endpoint.',
                    'Choosing an endpoint whose terms forbid training-on, advertising and secondary use.',
                    'Verifiable parental consent for under-13s where the school-authorisation exception '
                        . 'does not reach (COPPA § 312.5).',
                    'A written information-security programme and annual risk assessment (COPPA § 312.8(b)).',
                    'A published data-retention policy and schedule (COPPA § 312.10).',
                    'Data-breach notification process and timelines (NDPA § 5.4).',
                ],
            ],
            [
                'id'        => 'accessibility',
                'name'      => 'WCAG 2.2 AA / 508 / EN 301 549',
                'short'     => 'Accessibility of the chat surface',
                'authority' => 'W3C WCAG 2.2 AA; US Section 508 (36 CFR Part 1194); EU EN 301 549 V3',
                'supports'  => 'Supports robust, sanitised output that assistive technology can parse; '
                    . 'keyboard, focus, status-message, contrast and reflow conformance is delivered by the '
                    . 'front end and evidenced in a signed ACR/VPAT the institution owns.',
                'obligations' => [
                    ['label' => 'Robust, well-formed output AT can parse (WCAG SC 4.1.2 Name, Role, Value)',
                        'policies' => ['output-sanitise', 'injection-fence']],
                    ['label' => 'Links expose a real, non-scripted destination and role '
                        . '(WCAG SC 4.1.2, SC 2.4.4)',
                        'policies' => ['action-link-gating', 'output-sanitise']],
                ],
                'institution_owned' => [
                    'A signed ACR/VPAT in the edition for the buyer\'s market — the authoritative artifact.',
                    'Keyboard operability and no keyboard trap across the chat flow (SC 2.1.1/2.1.2).',
                    'Visible focus, not obscured by the composer/header (SC 2.4.7, SC 2.4.11).',
                    'Streaming replies announced via an ARIA live region (SC 4.1.3).',
                    'Text and UI-component contrast — depends on the active Moodle theme (SC 1.4.3/1.4.11).',
                    'Reflow at 320px / 400% zoom and pointer target size (SC 1.4.10, SC 2.5.8).',
                ],
            ],
            [
                'id'        => 'soc2',
                'name'      => 'SOC 2',
                'short'     => 'AICPA Trust Services Criteria (control evidence)',
                'authority' => 'AICPA TSP Section 100 (2017 TSC, 2022 points of focus) — CC6, CC7.2, '
                    . 'CC2.1, C1.1-C1.2, PI1.1-PI1.5',
                'supports'  => 'SOC 2 is an attestation an auditor issues to your institution, not something '
                    . 'a plugin holds; the plugin supplies control evidence toward the Security (CC6), '
                    . 'Confidentiality (C1) and Processing Integrity (PI1) criteria.',
                'obligations' => [
                    ['label' => 'Logical access restricted to authorised users (CC6.1)',
                        'policies' => ['staff-only', 'student-exclusion', 'capability-recheck', 'course-isolation',
                            'group-isolation', 'conversation-ownership', 'endpoint-access-gating']],
                    ['label' => 'Least privilege / segregation of duties (CC6.3)',
                        'policies' => ['course-scope-follows-moodle', 'capability-recheck', 'course-isolation',
                            'conversation-ownership']],
                    ['label' => 'Boundary protection and controlled transfer (CC6.6 / CC6.7)',
                        'policies' => ['byo-key-fail-closed', 'endpoint-access-gating', 'telemetry-metadata-only',
                            'output-sanitise']],
                    ['label' => 'Identify and maintain confidential information (C1.1)',
                        'policies' => ['domain-enablement', 'contact-pii-optin', 'feature-enablement']],
                    ['label' => 'Dispose of confidential information (C1.2)',
                        'policies' => ['privacy-erasure', 'export-capability']],
                    ['label' => 'Inputs/processing/outputs complete, accurate, authorised (PI1.1-PI1.5)',
                        'policies' => ['read-only-wall', 'action-link-gating', 'injection-fence',
                            'export-capability']],
                    ['label' => 'Monitor for anomalies; quality information for control (CC7.2 / CC2.1)',
                        'policies' => ['access-audit-log', 'telemetry-metadata-only', 'endpoint-access-gating']],
                ],
                'institution_owned' => [
                    'The SOC 2 examination itself, the control environment (CC1), risk assessment (CC3) and '
                        . 'change management (CC8) — an independent CPA firm examines the organisation.',
                    "Obtaining and assessing the chosen endpoint's own SOC 2 report and a DPA (CC9.2).",
                    'Encryption in transit/at rest and hardening of the Moodle host (CC6.1 / CC6.7).',
                    'Actually reviewing the log/telemetry and acting on anomalies (CC7.2 operation).',
                    'Provisioning/deprovisioning Moodle accounts and the staff allow-list (CC6.2).',
                    'Availability commitments, SLAs, backup and disaster recovery (A1).',
                ],
            ],
        ];
    }

    /**
     * Every distinct policy id referenced anywhere in the map — the set policy_manifest_test checks
     * resolves against policy::register(), so the map can never cite a policy that does not exist.
     *
     * @return string[]
     */
    public static function policy_ids_referenced(): array {
        $ids = [];
        foreach (self::frameworks() as $f) {
            foreach ($f['obligations'] as $o) {
                foreach ($o['policies'] as $pid) {
                    $ids[$pid] = true;
                }
            }
        }
        ksort($ids);
        return array_keys($ids);
    }
}
