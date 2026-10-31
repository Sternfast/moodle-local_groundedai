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
 * Restore of the course-scoped data for local_groundedai.
 *
 * Rebuilds a course's staff-assistant conversations (with their messages and attached files) into
 * the restored course, remapping the course context and the owning users.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Restores the conversations/messages/attachments backed up by backup_local_groundedai_plugin.
 */
class restore_local_groundedai_plugin extends restore_local_plugin {

    /**
     * Define the paths this plugin restores from a course backup.
     *
     * @return restore_path_element[]
     */
    protected function define_course_plugin_structure() {
        $paths = [];
        $paths[] = new restore_path_element(
            'local_groundedai_conversation', $this->get_pathfor('/conversations/conversation'));
        $paths[] = new restore_path_element(
            'local_groundedai_message', $this->get_pathfor('/conversations/conversation/messages/message'));
        $paths[] = new restore_path_element(
            'local_groundedai_attachment', $this->get_pathfor('/conversations/conversation/attachments/attachment'));
        return $paths;
    }

    /**
     * Restore one conversation into the target course's context.
     *
     * @param array|object $data
     */
    public function process_local_groundedai_conversation($data) {
        global $DB;
        $data = (object) $data;
        $oldid = $data->id;

        // The conversation belongs to a user; if that user was not restored, skip it (and its children).
        $data->userid = $this->get_mappingid('user', $data->userid);
        if (empty($data->userid)) {
            return;
        }
        $data->contextid = \context_course::instance($this->task->get_courseid())->id;

        unset($data->id);
        $newid = $DB->insert_record('local_groundedai_conversations', $data);
        $this->set_mapping('local_groundedai_conversation', $oldid, $newid);
    }

    /**
     * Restore one message under its restored conversation.
     *
     * @param array|object $data
     */
    public function process_local_groundedai_message($data) {
        global $DB;
        $data = (object) $data;
        $conversationid = $this->get_new_parentid('local_groundedai_conversation');
        if (empty($conversationid)) {
            return;
        }
        $data->conversationid = $conversationid;
        unset($data->id);
        $DB->insert_record('local_groundedai_messages', $data);
    }

    /**
     * Restore one attachment record under its restored conversation. The file itself is restored in
     * after_execute_course(), where the new file id is written back onto the record.
     *
     * @param array|object $data
     */
    public function process_local_groundedai_attachment($data) {
        global $DB;
        $data = (object) $data;
        $oldid = $data->id;
        $conversationid = $this->get_new_parentid('local_groundedai_conversation');
        if (empty($conversationid)) {
            return;
        }
        $data->conversationid = $conversationid;
        $data->contextid = \context_course::instance($this->task->get_courseid())->id;
        $data->userid = $this->get_mappingid('user', $data->userid) ?: $data->userid;
        $data->fileid = 0; // Resolved once the file is restored (see after_execute_course()).

        unset($data->id);
        $newid = $DB->insert_record('local_groundedai_attach', $data);
        // The final 'true' marks that this element carries files to remap.
        $this->set_mapping('local_groundedai_attachment', $oldid, $newid, true);
    }

    /**
     * After the course data is restored, bring the attachment files across and point each record at
     * its restored file.
     */
    protected function after_execute_course() {
        global $DB;

        // Restore the uploaded files into the new context, remapped to the new attach record ids.
        $this->add_related_files('local_groundedai', 'attachment', 'local_groundedai_attachment');

        // Write the new file id back onto each restored attachment.
        $fs = get_file_storage();
        $contextid = \context_course::instance($this->task->get_courseid())->id;
        $records = $DB->get_records('local_groundedai_attach', ['contextid' => $contextid, 'fileid' => 0]);
        foreach ($records as $rec) {
            $files = $fs->get_area_files($contextid, 'local_groundedai', 'attachment', $rec->id, 'id', false);
            if ($file = reset($files)) {
                $DB->set_field('local_groundedai_attach', 'fileid', $file->get_id(), ['id' => $rec->id]);
            }
        }
    }
}
