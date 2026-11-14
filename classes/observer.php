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
 * Event observers.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_groundedai;

/**
 * Keeps the stored conversations in step with the courses they belong to.
 */
class observer {
    /**
     * A course was deleted: remove the conversations, messages and attachment records stored in it.
     *
     * Conversations belong to a course context. Once that context is gone they can no longer be opened,
     * and the privacy subsystem can no longer reach them for export or erasure, so they go with the
     * course. Core has already removed the stored files with the context. Rows left in any context that
     * no longer exists (an activity inside the course) are removed in the same pass.
     *
     * @param \core\event\course_deleted $event The event.
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        global $DB;

        $gone = 'contextid = :contextid OR contextid NOT IN (SELECT id FROM {context})';
        $params = ['contextid' => (int) $event->contextid];

        $convids = $DB->get_fieldset_select('local_groundedai_conversations', 'id', $gone, $params);
        if ($convids) {
            [$insql, $inparams] = $DB->get_in_or_equal($convids, SQL_PARAMS_NAMED, 'c');
            $DB->delete_records_select('local_groundedai_messages', "conversationid $insql", $inparams);
            $DB->delete_records_select('local_groundedai_attach', "conversationid $insql", $inparams);
            $DB->delete_records_select('local_groundedai_conversations', "id $insql", $inparams);
        }
        $DB->delete_records_select('local_groundedai_attach', $gone, $params);
    }
}
