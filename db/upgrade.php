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
 * Upgrade steps.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Apply plugin upgrade steps.
 *
 * @param int $oldversion The version we are upgrading from.
 * @return bool
 */
function xmldb_local_groundedai_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026100904) {
        $conversations = new xmldb_table('local_groundedai_conversations');
        $conversations->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $conversations->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $conversations->add_field('contextid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $conversations->add_field('lane', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $conversations->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
        $conversations->add_field('archived', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $conversations->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $conversations->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $conversations->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $conversations->add_index(
            'userlanecontextarchived',
            XMLDB_INDEX_NOTUNIQUE,
            ['userid', 'lane', 'contextid', 'archived']
        );
        $conversations->add_index('timemodified', XMLDB_INDEX_NOTUNIQUE, ['timemodified']);
        if (!$dbman->table_exists($conversations)) {
            $dbman->create_table($conversations);
        }

        $messages = new xmldb_table('local_groundedai_messages');
        $messages->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $messages->add_field('conversationid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $messages->add_field('role', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $messages->add_field('content', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $messages->add_field('metadata', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $messages->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $messages->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $messages->add_key(
            'conversationid',
            XMLDB_KEY_FOREIGN,
            ['conversationid'],
            'local_groundedai_conversations',
            ['id']
        );
        $messages->add_index('conversationidtimecreated', XMLDB_INDEX_NOTUNIQUE, ['conversationid', 'timecreated']);
        if (!$dbman->table_exists($messages)) {
            $dbman->create_table($messages);
        }

        upgrade_plugin_savepoint(true, 2026100904, 'local', 'groundedai');
    }

    if ($oldversion < 2026101100) {
        $access = new xmldb_table('local_groundedai_access');
        $access->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $access->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $access->add_field('access', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
        $access->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $access->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $access->add_index('userid', XMLDB_INDEX_UNIQUE, ['userid']);
        if (!$dbman->table_exists($access)) {
            $dbman->create_table($access);
        }

        upgrade_plugin_savepoint(true, 2026101100, 'local', 'groundedai');
    }

    if ($oldversion < 2026101200) {
        $attach = new xmldb_table('local_groundedai_attach');
        $attach->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $attach->add_field('conversationid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $attach->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $attach->add_field('contextid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $attach->add_field('fileid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $attach->add_field('contenthash', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, '');
        $attach->add_field('filename', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
        $attach->add_field('mimetype', XMLDB_TYPE_CHAR, '128', null, XMLDB_NOTNULL, null, '');
        $attach->add_field('type', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, '');
        $attach->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'pending');
        $attach->add_field('chars', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $attach->add_field('content', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $attach->add_field('error', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $attach->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $attach->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $attach->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $attach->add_key(
            'conversationid',
            XMLDB_KEY_FOREIGN,
            ['conversationid'],
            'local_groundedai_conversations',
            ['id']
        );
        $attach->add_index('fileid', XMLDB_INDEX_UNIQUE, ['fileid']);
        $attach->add_index('contenthash', XMLDB_INDEX_NOTUNIQUE, ['contenthash']);
        $attach->add_index('conversationidstatus', XMLDB_INDEX_NOTUNIQUE, ['conversationid', 'status']);
        if (!$dbman->table_exists($attach)) {
            $dbman->create_table($attach);
        }

        upgrade_plugin_savepoint(true, 2026101200, 'local', 'groundedai');
    }

    if ($oldversion < 2026101402) {
        // Drop the redundant (conversationid, status) composite on the attachments table: the FK on
        // conversationid already provides a conversationid index, and no query filters by status.
        $attach = new xmldb_table('local_groundedai_attach');
        $index = new xmldb_index('conversationidstatus', XMLDB_INDEX_NOTUNIQUE, ['conversationid', 'status']);
        if ($dbman->index_exists($attach, $index)) {
            $dbman->drop_index($attach, $index);
        }

        upgrade_plugin_savepoint(true, 2026101402, 'local', 'groundedai');
    }

    if ($oldversion < 2026101601) {
        // Unified access-grants table: replaces config allowedroles/rolescopes and the
        // local_groundedai_access table with one row per grant — a role or a user, with course scope
        // and expiry. The data below is migrated in order (read every source, THEN retire it).
        $grants = new xmldb_table('local_groundedai_grants');
        $grants->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $grants->add_field('granteetype', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
        $grants->add_field('roleid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $grants->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $grants->add_field('coursescope', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'all');
        $grants->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $grants->add_field('expiry', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $grants->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $grants->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $grants->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $grants->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $grants->add_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);
        $grants->add_index('roleid', XMLDB_INDEX_NOTUNIQUE, ['roleid']);
        $grants->add_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
        if (!$dbman->table_exists($grants)) {
            $dbman->create_table($grants);
        }

        // Migrate via core APIs only (decoupled from access_manager so the step stays valid across future
        // refactors). record_exists guards make the whole step idempotent on a re-run.
        $now = time();
        $admin = get_admin();
        $adminid = $admin ? (int) $admin->id : 0;

        // Eligible = roles whose archetype is NOT forbidden (mirrors access_manager::FORBIDDEN_ARCHETYPES).
        $forbidden = ['student', 'guest', 'user', 'frontpage'];
        $eligible = [];
        foreach (get_all_roles() as $rid => $role) {
            if (!in_array($role->archetype, $forbidden, true)) {
                $eligible[(int) $rid] = true;
            }
        }

        // 1) Role grants from config 'allowedroles' (+ per-role 'rolescopes').
        $raw = (string) get_config('local_groundedai', 'allowedroles');
        $roleids = array_map('intval', preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY));
        $scopemap = json_decode((string) get_config('local_groundedai', 'rolescopes'), true);
        if (!is_array($scopemap)) {
            $scopemap = [];
        }
        $seen = [];
        foreach ($roleids as $rid) {
            $rid = (int) $rid;
            if (!isset($eligible[$rid]) || isset($seen[$rid])) {   // Drop stale/forbidden/duplicate ids.
                continue;
            }
            $seen[$rid] = true;
            $scope = (($scopemap[$rid] ?? 'all') === 'enrolled') ? 'enrolled' : 'all';
            $match = ['granteetype' => 'role', 'roleid' => $rid, 'coursescope' => $scope, 'courseid' => 0];
            if ($DB->record_exists('local_groundedai_grants', $match)) {
                continue;
            }
            $DB->insert_record('local_groundedai_grants', (object) [
                'granteetype' => 'role', 'roleid' => $rid, 'userid' => 0,
                'coursescope' => $scope, 'courseid' => 0, 'expiry' => 0,
                'timecreated' => $now, 'timemodified' => $now, 'usermodified' => $adminid,
            ]);
        }

        // 2) User grants from local_groundedai_access rows with access='allow' (scope all, never expires).
        // Legacy 'deny' rows are intentionally NOT migrated — the model is now a pure allowlist.
        $accesstable = new xmldb_table('local_groundedai_access');
        if ($dbman->table_exists($accesstable)) {
            $allow = $DB->get_fieldset_select('local_groundedai_access', 'userid', 'access = ?', ['allow']);
            $seenu = [];
            foreach ($allow as $uid) {
                $uid = (int) $uid;
                if (!$uid || isset($seenu[$uid])) {
                    continue;
                }
                $seenu[$uid] = true;
                $match = ['granteetype' => 'user', 'userid' => $uid, 'coursescope' => 'all', 'courseid' => 0];
                if ($DB->record_exists('local_groundedai_grants', $match)) {
                    continue;
                }
                $DB->insert_record('local_groundedai_grants', (object) [
                    'granteetype' => 'user', 'roleid' => 0, 'userid' => $uid,
                    'coursescope' => 'all', 'courseid' => 0, 'expiry' => 0,
                    'timecreated' => $now, 'timemodified' => $now, 'usermodified' => $adminid,
                ]);
            }
        }

        // 3) Retire the old storage now that it is migrated (ordering matters: read above, then drop).
        unset_config('allowedroles', 'local_groundedai');
        unset_config('rolescopes', 'local_groundedai');
        if ($dbman->table_exists($accesstable)) {
            $dbman->drop_table($accesstable);
        }

        upgrade_plugin_savepoint(true, 2026101601, 'local', 'groundedai');
    }

    if ($oldversion < 2026101602) {
        // Data domains + sensitive features move from global config onto each grant. Add the columns,
        // backfill every existing grant with the CURRENT global settings (so behaviour is unchanged on
        // upgrade), then retire the global config keys.
        $grants = new xmldb_table('local_groundedai_grants');
        foreach (['domains', 'features'] as $field) {
            $xfield = new xmldb_field($field, XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
            $xfield->setPrevious($field === 'domains' ? 'courseid' : 'domains');
            if (!$dbman->field_exists($grants, $xfield)) {
                $dbman->add_field($grants, $xfield);
            }
        }

        $alldomains = ['courses', 'grades', 'participants', 'dates', 'progress', 'actions'];
        $ondomains = [];
        foreach ($alldomains as $d) {
            if (get_config('local_groundedai', 'domain' . $d)) {
                $ondomains[] = $d;
            }
        }
        $onfeatures = [];
        if (get_config('local_groundedai', 'participantcontact')) {
            $onfeatures[] = 'email';
        }
        if (get_config('local_groundedai', 'enableupload')) {
            $onfeatures[] = 'upload';
        }
        if (get_config('local_groundedai', 'enableexport')) {
            $onfeatures[] = 'export';
        }
        // Backfill only grants still at the empty default, so re-running this step after an interruption
        // (the configs below are already unset) cannot overwrite grants populated on the first pass.
        $DB->set_field_select('local_groundedai_grants', 'domains', implode(',', $ondomains), 'domains = ?', ['']);
        $DB->set_field_select('local_groundedai_grants', 'features', implode(',', $onfeatures), 'features = ?', ['']);

        foreach ($alldomains as $d) {
            unset_config('domain' . $d, 'local_groundedai');
        }
        unset_config('participantcontact', 'local_groundedai');
        unset_config('enableupload', 'local_groundedai');
        unset_config('enableexport', 'local_groundedai');

        upgrade_plugin_savepoint(true, 2026101602, 'local', 'groundedai');
    }

    return true;
}
