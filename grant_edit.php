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
 * Add, edit or delete one access grant for the staff assistant.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_groundedai\local\access_manager;
use local_groundedai\form\grant_form;

admin_externalpage_setup('local_groundedai_governance');
$context = context_system::instance();
require_capability('local/groundedai:manage', $context);

$id = optional_param('id', 0, PARAM_INT);
$type = optional_param('type', access_manager::GRANTEE_ROLE, PARAM_ALPHA);
$action = optional_param('action', '', PARAM_ALPHA);

$governanceurl = new moodle_url('/local/groundedai/governance.php');
$PAGE->set_url(new moodle_url('/local/groundedai/grant_edit.php'));

// Load an existing grant (edit/delete); derive its grantee type.
$grant = $id ? access_manager::get_grant($id) : null;
if ($id && !$grant) {
    redirect($governanceurl);
}
if ($grant) {
    $type = (string) $grant->granteetype;
}

// Delete flow — confirm, then delete.
if ($action === 'delete' && $grant) {
    if (optional_param('confirm', 0, PARAM_BOOL) && confirm_sesskey()) {
        access_manager::delete_grant((int) $grant->id);
        local_groundedai_log_grant_change('deleted', $grant);
        redirect(
            $governanceurl,
            get_string('grant_deleted', 'local_groundedai'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    echo $OUTPUT->header();
    $name = local_groundedai_grant_name($grant);
    $yes = new moodle_url(
        '/local/groundedai/grant_edit.php',
        ['id' => $grant->id, 'action' => 'delete', 'confirm' => 1, 'sesskey' => sesskey()]
    );
    echo $OUTPUT->confirm(get_string('grant_confirmdelete', 'local_groundedai', $name), $yes, $governanceurl);
    echo $OUTPUT->footer();
    exit;
}

$form = new grant_form($PAGE->url->out(false), ['type' => $type, 'grantid' => $id]);

if ($form->is_cancelled()) {
    redirect($governanceurl);
} else if ($data = $form->get_data()) {
    // Resolve expiry: never -> 0; date -> the END of the chosen day, so a grant is usable all through its
    // expiry date and a same-day expiry is not dead the instant it is saved; days -> now + N seconds.
    $expiry = 0;
    if ($data->expirytype === 'date') {
        $expiry = (int) $data->expirydate + DAYSECS - 1;
    } else if ($data->expirytype === 'days') {
        $expiry = time() + (int) $data->expirydays;
    }
    $scope = (string) $data->coursescope;
    // A specific-courses grant spans one row per selected course; any other scope is a single row.
    $courseids = ($scope === access_manager::SCOPE_COURSE)
        ? array_values(array_unique(array_filter(array_map('intval', (array) $data->courseids), fn($c) => $c > 0)))
        : [0];

    // The data domains + sensitive features this grant carries.
    $domains = [];
    foreach (access_manager::DOMAINS as $d) {
        if (!empty($data->{'domain_' . $d})) {
            $domains[] = $d;
        }
    }
    $features = [];
    foreach (access_manager::FEATURES as $f) {
        if (!empty($data->{'feature_' . $f})) {
            $features[] = $f;
        }
    }

    $roleid = $id ? (int) $grant->roleid : (int) ($data->roleid ?? 0);
    $userid = $id ? (int) $grant->userid : (int) ($data->userid ?? 0);
    $saved = 0;
    try {
        if ($id) {
            // Update this row to the first course; add rows for any extra courses on the same grantee.
            access_manager::update_grant($id, ['coursescope' => $scope, 'courseid' => $courseids[0],
                'expiry' => $expiry, 'domains' => $domains, 'features' => $features]);
            local_groundedai_log_grant_change('updated', access_manager::get_grant($id));
            $saved++;
            $courseids = array_slice($courseids, 1);
        }
        foreach ($courseids as $cid) {
            try {
                $newid = ($type === access_manager::GRANTEE_USER)
                    ? access_manager::add_user_grant($userid, $scope, $cid, $expiry, $domains, $features)
                    : access_manager::add_role_grant($roleid, $scope, $cid, $expiry, $domains, $features);
                local_groundedai_log_grant_change('created', access_manager::get_grant($newid));
                $saved++;
            } catch (\moodle_exception $e) {
                // Skip a duplicate course silently; re-throw anything else.
                if ($e->errorcode !== 'grantexists') {
                    throw $e;
                }
            }
        }
    } catch (\moodle_exception $e) {
        redirect($governanceurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
    redirect(
        $governanceurl,
        get_string('grant_courses_added', 'local_groundedai', $saved),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Editing: seed the form from the stored grant.
if ($grant) {
    $toform = [
        'id'          => (int) $grant->id,
        'type'        => (string) $grant->granteetype,
        'coursescope' => (string) $grant->coursescope,
        'courseids'   => (int) $grant->courseid ? [(int) $grant->courseid] : [],
    ];
    if ((string) $grant->granteetype === access_manager::GRANTEE_USER) {
        $toform['userid'] = (int) $grant->userid;
    } else {
        $toform['roleid'] = (int) $grant->roleid;
    }
    if ((int) $grant->expiry === 0) {
        $toform['expirytype'] = 'never';
    } else {
        $toform['expirytype'] = 'date';
        $toform['expirydate'] = (int) $grant->expiry;
    }
    $grantdomains = array_filter(explode(',', (string) $grant->domains));
    foreach (access_manager::DOMAINS as $d) {
        $toform['domain_' . $d] = in_array($d, $grantdomains, true) ? 1 : 0;
    }
    $grantfeatures = array_filter(explode(',', (string) $grant->features));
    foreach (access_manager::FEATURES as $f) {
        $toform['feature_' . $f] = in_array($f, $grantfeatures, true) ? 1 : 0;
    }
    $form->set_data($toform);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string($id ? 'grant_edit' : ($type === access_manager::GRANTEE_USER
    ? 'grant_adduser_title' : 'grant_addrole_title'), 'local_groundedai'));
$form->display();
echo $OUTPUT->footer();

/**
 * A short label naming a grant's grantee, for confirmations and the log.
 *
 * @param \stdClass $grant The grant record.
 * @return string
 */
function local_groundedai_grant_name(\stdClass $grant): string {
    global $DB;
    if ((string) $grant->granteetype === access_manager::GRANTEE_USER) {
        $user = $DB->get_record('user', ['id' => (int) $grant->userid]);
        return $user ? fullname($user) : get_string('grant_user_none', 'local_groundedai');
    }
    $names = role_get_names(\context_system::instance());
    $rid = (int) $grant->roleid;
    return isset($names[$rid]) ? $names[$rid]->localname : get_string('grant_role_none', 'local_groundedai');
}

/**
 * Log a grant change to the governance audit trail — ids and config values only, no names or emails.
 *
 * @param string $changeaction created|updated|deleted.
 * @param \stdClass|null $grant The grant (post-change for create/update; pre-delete for delete).
 */
function local_groundedai_log_grant_change(string $changeaction, ?\stdClass $grant): void {
    if (!$grant) {
        return;
    }
    try {
        \local_groundedai\event\grant_changed::create([
            'context'  => \context_system::instance(),
            'objectid' => (int) $grant->id,
            'other'    => [
                'action'      => $changeaction,
                'grantee'     => (string) $grant->granteetype . ' '
                    . (int) ((string) $grant->granteetype === access_manager::GRANTEE_USER
                        ? $grant->userid : $grant->roleid),
                'coursescope' => (string) $grant->coursescope,
                'courseid'    => (int) $grant->courseid,
                'expiry'      => (int) $grant->expiry,
            ],
        ])->trigger();
    } catch (\Throwable $e) {
        debugging('local_groundedai could not log grant_changed: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }
}
