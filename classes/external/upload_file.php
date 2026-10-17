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
 * External function: attach an uploaded draft file to a conversation.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_groundedai\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_groundedai\local\attachment_manager;
use local_groundedai\local\conversation;

/**
 * Move a just-uploaded draft file into the conversation and queue extraction.
 */
class upload_file extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'conversationid' => new external_value(PARAM_INT, 'conversation id'),
            'draftitemid'    => new external_value(PARAM_INT, 'the draft area the file was uploaded into'),
        ]);
    }

    /**
     * Attach.
     *
     * @param int $conversationid Conversation id.
     * @param int $draftitemid Draft item id.
     * @return array
     */
    public static function execute(int $conversationid, int $draftitemid): array {
        global $USER;
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['conversationid' => $conversationid, 'draftitemid' => $draftitemid]
        );
        self::validate_context(conversation::context_for($params['conversationid']));
        if (!\local_groundedai\local\access_manager::feature_enabled($USER->id, 'upload')) {
            throw new \moodle_exception('uploaddisabled', 'local_groundedai');
        }

        $newid = attachment_manager::add_file($params['conversationid'], $USER->id, $params['draftitemid']);

        $out = [];
        foreach (attachment_manager::list_for($params['conversationid'], $USER->id) as $row) {
            $out[] = (array) $row;
        }
        return ['newid' => $newid, 'attachments' => $out];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'newid'       => new external_value(PARAM_INT, 'the new attachment id'),
            'attachments' => new external_multiple_structure(get_attachments::attachment_structure()),
        ]);
    }
}
