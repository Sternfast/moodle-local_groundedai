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
 * External function: send a message and get the reply.
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
 * Persists the user's message, runs the lane handler, persists the reply, and returns the new messages.
 */
class send_message extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'conversationid' => new external_value(PARAM_INT, 'conversation id'),
            'text'           => new external_value(PARAM_RAW, 'the message'),
            'cmid'           => new external_value(PARAM_INT, 'the activity being viewed (advisory page hint)', VALUE_DEFAULT, 0),
            'pagetype'       => new external_value(PARAM_ALPHAEXT, 'the page type being viewed (advisory hint)', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Send.
     *
     * @param int $conversationid Conversation id.
     * @param string $text The message.
     * @param int $cmid The activity being viewed, if any (advisory page hint).
     * @param string $pagetype The page type being viewed, if any (advisory hint).
     * @return array
     */
    public static function execute(int $conversationid, string $text, int $cmid = 0, string $pagetype = ''): array {
        global $USER;
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['conversationid' => $conversationid, 'text' => $text, 'cmid' => $cmid, 'pagetype' => $pagetype]
        );
        self::validate_context(conversation::context_for($params['conversationid']));
        // The 'pagetype' value is still accepted and validated at the WS boundary for the client, but it
        // is an advisory hint that nothing consumes yet, so it is no longer forwarded into the handler.
        return ['messages' => conversation::send(
            $params['conversationid'],
            $USER->id,
            $params['text'],
            $params['cmid']
        )];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'messages' => new external_multiple_structure(get_messages::message_structure()),
        ]);
    }
}
