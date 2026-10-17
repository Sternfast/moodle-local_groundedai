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
 * External function: remove an attachment from a conversation.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_groundedai\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_groundedai\local\attachment_manager;
use local_groundedai\local\conversation;

/**
 * Delete one attachment (and its stored file) from a conversation the user owns.
 */
class delete_attachment extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'conversationid' => new external_value(PARAM_INT, 'conversation id'),
            'attachmentid'   => new external_value(PARAM_INT, 'attachment id'),
        ]);
    }

    /**
     * Delete.
     *
     * @param int $conversationid Conversation id.
     * @param int $attachmentid Attachment id.
     * @return array
     */
    public static function execute(int $conversationid, int $attachmentid): array {
        global $USER;
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['conversationid' => $conversationid, 'attachmentid' => $attachmentid]
        );
        self::validate_context(conversation::context_for($params['conversationid']));
        attachment_manager::delete($params['attachmentid'], $USER->id);
        return ['status' => true];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_BOOL, 'true on success'),
        ]);
    }
}
