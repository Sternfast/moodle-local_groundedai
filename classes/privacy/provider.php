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
 * Privacy provider.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_groundedai\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy for the chat conversations, messages, attachments and access grants this plugin stores.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\user_preference_provider {
    /**
     * Describe the personal data this plugin stores and sends.
     *
     * @param collection $collection The metadata collection.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_groundedai_conversations', [
            'userid'       => 'privacy:metadata:conversations:userid',
            'name'         => 'privacy:metadata:conversations:name',
            'lane'         => 'privacy:metadata:conversations:lane',
            'timecreated'  => 'privacy:metadata:conversations:timecreated',
            'timemodified' => 'privacy:metadata:conversations:timemodified',
        ], 'privacy:metadata:conversations');

        $collection->add_database_table('local_groundedai_messages', [
            'role'        => 'privacy:metadata:messages:role',
            'content'     => 'privacy:metadata:messages:content',
            'timecreated' => 'privacy:metadata:messages:timecreated',
        ], 'privacy:metadata:messages');

        $collection->add_database_table('local_groundedai_attach', [
            'userid'      => 'privacy:metadata:attach:userid',
            'filename'    => 'privacy:metadata:attach:filename',
            'content'     => 'privacy:metadata:attach:content',
            'timecreated' => 'privacy:metadata:attach:timecreated',
        ], 'privacy:metadata:attach');

        $collection->add_database_table('local_groundedai_grants', [
            'userid'       => 'privacy:metadata:grants:userid',
            'roleid'       => 'privacy:metadata:grants:roleid',
            'coursescope'  => 'privacy:metadata:grants:coursescope',
            'courseid'     => 'privacy:metadata:grants:courseid',
            'domains'      => 'privacy:metadata:grants:domains',
            'features'     => 'privacy:metadata:grants:features',
            'expiry'       => 'privacy:metadata:grants:expiry',
            'timecreated'  => 'privacy:metadata:grants:timecreated',
            'timemodified' => 'privacy:metadata:grants:timemodified',
            'usermodified' => 'privacy:metadata:grants:usermodified',
        ], 'privacy:metadata:grants');

        // Data leaving Moodle.
        $collection->add_external_location_link('modelprovider', [
            'question' => 'privacy:metadata:modelprovider:question',
            'content'  => 'privacy:metadata:modelprovider:content',
        ], 'privacy:metadata:modelprovider');

        $collection->add_external_location_link('otlpendpoint', [
            'spans' => 'privacy:metadata:otlpendpoint:spans',
        ], 'privacy:metadata:otlpendpoint');

        // Per-user preferences.
        $collection->add_user_preference(
            'local_groundedai_hidelauncher',
            'privacy:metadata:preference:hidelauncher'
        );
        $collection->add_user_preference(
            'local_groundedai_tuning_staff',
            'privacy:metadata:preference:tuningstaff'
        );

        return $collection;
    }

    /**
     * Export the plugin's user preferences for a user.
     *
     * @param int $userid The user id.
     */
    public static function export_user_preferences(int $userid): void {
        $hide = get_user_preferences('local_groundedai_hidelauncher', null, $userid);
        if ($hide !== null) {
            writer::export_user_preference(
                'local_groundedai',
                'local_groundedai_hidelauncher',
                $hide ? '1' : '0',
                get_string('privacy:metadata:preference:hidelauncher', 'local_groundedai')
            );
        }
        $tuning = get_user_preferences('local_groundedai_tuning_staff', null, $userid);
        if ($tuning !== null && $tuning !== '') {
            writer::export_user_preference(
                'local_groundedai',
                'local_groundedai_tuning_staff',
                $tuning,
                get_string('privacy:metadata:preference:tuningstaff', 'local_groundedai')
            );
        }
    }

    /**
     * Contexts that hold data for a user.
     *
     * @param int $userid The user id.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        // Contexts where the user owns conversations.
        $contextlist->add_from_sql(
            "SELECT DISTINCT contextid FROM {local_groundedai_conversations} WHERE userid = :userid",
            ['userid' => $userid]
        );

        // Contexts where the user owns attachments (normally the same, but be exhaustive).
        $contextlist->add_from_sql(
            "SELECT DISTINCT contextid FROM {local_groundedai_attach} WHERE userid = :userid",
            ['userid' => $userid]
        );

        // A user grant naming this user is site-wide: attribute it to the system context.
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {context} ctx
               JOIN {local_groundedai_grants} g ON g.userid = :userid AND g.granteetype = :gtype
              WHERE ctx.contextlevel = :syslevel",
            ['userid' => $userid, 'gtype' => 'user', 'syslevel' => CONTEXT_SYSTEM]
        );

        return $contextlist;
    }

    /**
     * Users who have data in a context.
     *
     * @param userlist $userlist The userlist to add users to.
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();

        $userlist->add_from_sql(
            'userid',
            "SELECT userid FROM {local_groundedai_conversations} WHERE contextid = :contextid",
            ['contextid' => $context->id]
        );
        $userlist->add_from_sql(
            'userid',
            "SELECT userid FROM {local_groundedai_attach} WHERE contextid = :contextid",
            ['contextid' => $context->id]
        );

        if ($context instanceof \context_system) {
            $userlist->add_from_sql(
                'userid',
                "SELECT userid FROM {local_groundedai_grants} WHERE granteetype = :gtype",
                ['gtype' => 'user']
            );
        }
    }

    /**
     * Export all data for the approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            $conversations = $DB->get_records(
                'local_groundedai_conversations',
                ['userid' => $userid, 'contextid' => $context->id],
                'timecreated ASC'
            );
            foreach ($conversations as $conv) {
                $messages = $DB->get_records(
                    'local_groundedai_messages',
                    ['conversationid' => $conv->id],
                    'timecreated ASC',
                    'id, role, content, timecreated'
                );
                $data = (object) [
                    'name'         => $conv->name,
                    'lane'         => $conv->lane,
                    'timecreated'  => \core_privacy\local\request\transform::datetime($conv->timecreated),
                    'timemodified' => \core_privacy\local\request\transform::datetime($conv->timemodified),
                    'messages'     => array_map(static function ($m) {
                        return [
                            'role'        => $m->role,
                            'content'     => $m->content,
                            'timecreated' => \core_privacy\local\request\transform::datetime($m->timecreated),
                        ];
                    }, array_values($messages)),
                ];
                $subcontext = [get_string('pluginname', 'local_groundedai'), 'conversation-' . $conv->id];
                writer::with_context($context)->export_data($subcontext, $data);
                // Any export files generated for this conversation.
                writer::with_context($context)->export_area_files($subcontext, 'local_groundedai', 'export', $conv->id);
            }

            $attachments = $DB->get_records(
                'local_groundedai_attach',
                ['userid' => $userid, 'contextid' => $context->id],
                'timecreated ASC'
            );
            foreach ($attachments as $att) {
                $subcontext = [get_string('pluginname', 'local_groundedai'), 'attachment-' . $att->id];
                writer::with_context($context)->export_data($subcontext, (object) [
                    'filename'    => $att->filename,
                    'status'      => $att->status,
                    'content'     => $att->content,
                    'timecreated' => \core_privacy\local\request\transform::datetime($att->timecreated),
                ]);
                writer::with_context($context)->export_area_files($subcontext, 'local_groundedai', 'attachment', $att->id);
            }

            if ($context instanceof \context_system) {
                $grants = $DB->get_records(
                    'local_groundedai_grants',
                    ['userid' => $userid, 'granteetype' => 'user'],
                    'timecreated ASC'
                );
                if ($grants) {
                    $rows = array_map(static function ($g) {
                        return [
                            'coursescope'  => $g->coursescope,
                            'courseid'     => (int) $g->courseid,
                            'expiry'       => (int) $g->expiry
                                ? \core_privacy\local\request\transform::datetime($g->expiry) : 'never',
                            'timecreated'  => \core_privacy\local\request\transform::datetime($g->timecreated),
                            'timemodified' => \core_privacy\local\request\transform::datetime($g->timemodified),
                        ];
                    }, array_values($grants));
                    writer::with_context($context)->export_data(
                        [get_string('pluginname', 'local_groundedai'),
                            get_string('privacy:accessgrants', 'local_groundedai')],
                        (object) ['grants' => $rows]
                    );
                }
            }
        }
    }

    /**
     * Delete all plugin data for every user in a context.
     *
     * @param \context $context The context.
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        self::delete_conversations_select($context, 'contextid = :contextid', ['contextid' => $context->id]);

        // Attachments in this context (and their files).
        $attachments = $DB->get_records('local_groundedai_attach', ['contextid' => $context->id], '', 'id');
        self::delete_attachments(array_keys($attachments), $context->id);

        if ($context instanceof \context_system) {
            // User grants hold a user id; role grants carry no personal data and survive.
            $DB->delete_records('local_groundedai_grants', ['granteetype' => 'user']);
        }
    }

    /**
     * Delete all plugin data for a user across the approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            self::delete_conversations_select(
                $context,
                'userid = :userid AND contextid = :contextid',
                ['userid' => $userid, 'contextid' => $context->id]
            );

            $attachments = $DB->get_records(
                'local_groundedai_attach',
                ['userid' => $userid, 'contextid' => $context->id],
                '',
                'id'
            );
            self::delete_attachments(array_keys($attachments), $context->id);

            if ($context instanceof \context_system) {
                $DB->delete_records('local_groundedai_grants', ['userid' => $userid, 'granteetype' => 'user']);
            }
        }
    }

    /**
     * Delete data for a set of users in a context.
     *
     * @param approved_userlist $userlist The approved users.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');

        self::delete_conversations_select(
            $context,
            "userid $insql AND contextid = :contextid",
            $params + ['contextid' => $context->id]
        );

        $attachments = $DB->get_records_select(
            'local_groundedai_attach',
            "userid $insql AND contextid = :contextid",
            $params + ['contextid' => $context->id],
            '',
            'id'
        );
        self::delete_attachments(array_keys($attachments), $context->id);

        if ($context instanceof \context_system) {
            $DB->delete_records_select(
                'local_groundedai_grants',
                "granteetype = :gtype AND userid $insql",
                $params + ['gtype' => 'user']
            );
        }
    }

    /**
     * Delete conversations matching a WHERE clause, with their messages and export files.
     *
     * @param \context $context The context the export files live in.
     * @param string $where SQL WHERE fragment on local_groundedai_conversations.
     * @param array $params Named parameters.
     */
    protected static function delete_conversations_select(\context $context, string $where, array $params): void {
        global $DB;
        $convids = $DB->get_fieldset_select('local_groundedai_conversations', 'id', $where, $params);
        if (!$convids) {
            return;
        }
        $fs = get_file_storage();
        foreach ($convids as $convid) {
            $fs->delete_area_files($context->id, 'local_groundedai', 'export', $convid);
        }
        [$insql, $inparams] = $DB->get_in_or_equal($convids, SQL_PARAMS_NAMED, 'c');
        $DB->delete_records_select('local_groundedai_messages', "conversationid $insql", $inparams);
        $DB->delete_records_select('local_groundedai_conversations', "id $insql", $inparams);
    }

    /**
     * Delete a set of attachments with their stored files.
     *
     * @param array $attachids Attachment ids.
     * @param int $contextid The context the attachment files live in.
     */
    protected static function delete_attachments(array $attachids, int $contextid): void {
        global $DB;
        if (!$attachids) {
            return;
        }
        $fs = get_file_storage();
        foreach ($attachids as $attachid) {
            $fs->delete_area_files($contextid, 'local_groundedai', 'attachment', $attachid);
        }
        [$insql, $inparams] = $DB->get_in_or_equal($attachids, SQL_PARAMS_NAMED, 'a');
        $DB->delete_records_select('local_groundedai_attach', "id $insql", $inparams);
    }
}
