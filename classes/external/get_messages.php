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
 * External function: fetch a page of messages.
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
use local_groundedai\local\conversation;

/**
 * Returns a cursor-paginated page of a conversation's messages.
 */
class get_messages extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'conversationid' => new external_value(PARAM_INT, 'conversation id'),
            'before'         => new external_value(PARAM_INT, 'return messages with id < this; 0 = latest', VALUE_DEFAULT, 0),
            'limit'          => new external_value(PARAM_INT, 'page size', VALUE_DEFAULT, 30),
        ]);
    }

    /**
     * Fetch.
     *
     * @param int $conversationid Conversation id.
     * @param int $before Cursor.
     * @param int $limit Page size.
     * @return array
     */
    public static function execute(int $conversationid, int $before, int $limit): array {
        global $USER;
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['conversationid' => $conversationid, 'before' => $before, 'limit' => $limit]
        );
        $context = conversation::context_for($params['conversationid']);
        self::validate_context($context);
        // Honour the master switch / access revocation on reads too, not just on create/send.
        conversation::require_access($context);
        return conversation::get_messages($params['conversationid'], $USER->id, $params['before'], $params['limit']);
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'messages' => new external_multiple_structure(self::message_structure()),
            'hasmore'  => new external_value(PARAM_BOOL, 'more older messages exist'),
        ]);
    }

    /**
     * The shape of one message row (shared with send_message).
     *
     * @return external_single_structure
     */
    public static function message_structure(): external_single_structure {
        return new external_single_structure([
            'id'          => new external_value(PARAM_INT, 'message id'),
            'role'        => new external_value(PARAM_ALPHA, 'user, assistant or tool'),
            'content'     => new external_value(PARAM_RAW, 'message text'),
            'contenthtml' => new external_value(PARAM_RAW, 'rendered HTML (assistant only)', VALUE_OPTIONAL),
            'metadata'    => new external_value(PARAM_RAW, 'JSON metadata', VALUE_OPTIONAL),
            'timecreated' => new external_value(PARAM_INT, 'created'),
        ]);
    }
}
