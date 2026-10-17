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
 * External function: archive or unarchive a conversation.
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
use local_groundedai\local\conversation;

/**
 * Toggles the archived flag on a conversation the user owns.
 */
class archive_conversation extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id'       => new external_value(PARAM_INT, 'conversation id'),
            'archived' => new external_value(PARAM_BOOL, 'target archived state'),
        ]);
    }

    /**
     * Archive / unarchive.
     *
     * @param int $id Conversation id.
     * @param bool $archived Target state.
     * @return array
     */
    public static function execute(int $id, bool $archived): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['id' => $id, 'archived' => $archived]);
        self::validate_context(conversation::context_for($params['id']));
        conversation::set_archived($params['id'], $USER->id, $params['archived']);
        return ['status' => true];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure(['status' => new external_value(PARAM_BOOL, 'ok')]);
    }
}
