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
 * External function: list a conversation's attachments.
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
 * Return the attachments (metadata only) on a conversation.
 */
class get_attachments extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'conversationid' => new external_value(PARAM_INT, 'conversation id'),
        ]);
    }

    /**
     * List.
     *
     * @param int $conversationid Conversation id.
     * @return array
     */
    public static function execute(int $conversationid): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['conversationid' => $conversationid]);
        $context = conversation::context_for($params['conversationid']);
        self::validate_context($context);
        // Honour the master switch / access revocation on reads too, not just on create/send.
        conversation::require_access($context);
        $rows = attachment_manager::list_for($params['conversationid'], $USER->id);
        $out = [];
        foreach ($rows as $row) {
            $out[] = (array) $row;
        }
        return ['attachments' => $out];
    }

    /**
     * The shape of one attachment row, shared with upload_file.
     *
     * @return external_single_structure
     */
    public static function attachment_structure(): external_single_structure {
        return new external_single_structure([
            'id'          => new external_value(PARAM_INT, 'attachment id'),
            'filename'    => new external_value(PARAM_FILE, 'file name'),
            'type'        => new external_value(PARAM_ALPHA, 'detected type, or empty'),
            'status'      => new external_value(PARAM_ALPHA, 'read status'),
            'chars'       => new external_value(PARAM_INT, 'characters extracted'),
            'error'       => new external_value(PARAM_TEXT, 'short error, if any', VALUE_OPTIONAL),
            'timecreated' => new external_value(PARAM_INT, 'created time', VALUE_OPTIONAL),
        ]);
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'attachments' => new external_multiple_structure(self::attachment_structure()),
        ]);
    }
}
