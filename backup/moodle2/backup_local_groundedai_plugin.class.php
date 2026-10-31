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
 * Backup of the course-scoped data for local_groundedai.
 *
 * The staff assistant can be opened in a course context; a user's chat conversations there, their
 * messages, and the files they attached are per-user data that belongs to the course. It is only
 * included when the backup is set to include user information.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Defines the course-level backup structure for this local plugin.
 */
class backup_local_groundedai_plugin extends backup_local_plugin {

    /**
     * Returns the structure to be processed by this plugin at the course level.
     *
     * @return backup_plugin_element The plugin element with its course-scoped children.
     */
    protected function define_course_plugin_structure() {
        // Conversations are per-user data, so only back them up with user information.
        $userinfo = $this->get_setting_value('users');

        $plugin = $this->get_plugin_element(null, null, null);

        $wrapper = new backup_nested_element($this->get_recommended_name());
        $plugin->add_child($wrapper);

        $conversations = new backup_nested_element('conversations');
        $conversation = new backup_nested_element('conversation', ['id'], [
            'userid', 'lane', 'name', 'archived', 'timecreated', 'timemodified',
        ]);
        $messages = new backup_nested_element('messages');
        $message = new backup_nested_element('message', ['id'], [
            'role', 'content', 'metadata', 'timecreated',
        ]);
        $attachments = new backup_nested_element('attachments');
        $attachment = new backup_nested_element('attachment', ['id'], [
            'userid', 'contenthash', 'filename', 'mimetype', 'type', 'status',
            'chars', 'content', 'error', 'timecreated', 'timemodified',
        ]);

        $wrapper->add_child($conversations);
        $conversations->add_child($conversation);
        $conversation->add_child($messages);
        $messages->add_child($message);
        $conversation->add_child($attachments);
        $attachments->add_child($attachment);

        if ($userinfo) {
            // Only the conversations whose context is this course's context.
            $conversation->set_source_sql(
                'SELECT * FROM {local_groundedai_conversations} WHERE contextid = ?',
                [backup::VAR_CONTEXTID]
            );
            $message->set_source_table('local_groundedai_messages', ['conversationid' => backup::VAR_PARENTID]);
            $attachment->set_source_table('local_groundedai_attach', ['conversationid' => backup::VAR_PARENTID]);

            // Keep the owning users so the data restores against the right people.
            $conversation->annotate_ids('user', 'userid');
            $attachment->annotate_ids('user', 'userid');

            // The uploaded source files live in the 'attachment' filearea, keyed by the attach record id.
            $attachment->annotate_files('local_groundedai', 'attachment', 'id');
        }

        return $plugin;
    }
}
