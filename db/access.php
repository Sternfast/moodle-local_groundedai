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
 * Capabilities.
 *
 * The staff assistant is opened through the plugin's own access grants — a strict allowlist of
 * role or user grants, each with a course scope and optional expiry, managed on the Governance
 * page (there is no block list). A grant never widens data access: every retrieval re-checks the
 * relevant core capability (moodle/grade:viewall, moodle/course:viewparticipants, ...) in the
 * item's own context, so a user only ever sees what Moodle already lets them see. The two
 * capabilities below are separate: who may request an export, and who may manage the plugin.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [

    // Request an export (produces a file of records the user may already see). Personal-data risk.
    'local/groundedai:export' => [
        'riskbitmask'  => RISK_PERSONAL,
        'captype'      => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => [
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],

    // Site configuration.
    'local/groundedai:manage' => [
        'riskbitmask'         => RISK_CONFIG,
        'captype'             => 'write',
        'contextlevel'        => CONTEXT_SYSTEM,
        'archetypes'          => ['manager' => CAP_ALLOW],
        'clonepermissionsfrom' => 'moodle/site:config',
    ],
];
