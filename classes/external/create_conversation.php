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
 * External function: create a conversation.
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
 * Creates an empty conversation and returns its id.
 */
class create_conversation extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'lane'      => new external_value(PARAM_ALPHA, 'conversation lane (staff)'),
            'contextid' => new external_value(PARAM_INT, 'context id'),
        ]);
    }

    /**
     * Create.
     *
     * @param string $lane Lane.
     * @param int $contextid Context id.
     * @return array
     */
    public static function execute(string $lane, int $contextid): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['lane' => $lane, 'contextid' => $contextid]);
        $context = \context::instance_by_id($params['contextid']);
        self::validate_context($context);
        conversation::require_access($context);
        // The 'lane' value is still validated for the WS contract, but create() always makes a staff chat.
        return ['id' => conversation::create($USER->id, $params['contextid'])];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure(['id' => new external_value(PARAM_INT, 'new conversation id')]);
    }
}
