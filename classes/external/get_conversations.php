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
 * External function: list a user's conversations.
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
 * Returns the acting user's conversations in a context + lane.
 */
class get_conversations extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'lane'      => new external_value(PARAM_ALPHA, 'conversation lane (staff)'),
            'contextid' => new external_value(PARAM_INT, 'context id'),
            'archived'  => new external_value(PARAM_BOOL, 'archived set?', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * List conversations.
     *
     * @param string $lane Lane.
     * @param int $contextid Context id.
     * @param bool $archived Archived set.
     * @return array
     */
    public static function execute(string $lane, int $contextid, bool $archived): array {
        global $USER;
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['lane' => $lane, 'contextid' => $contextid, 'archived' => $archived]
        );
        $context = \context::instance_by_id($params['contextid']);
        self::validate_context($context);
        conversation::require_access($context);
        return conversation::get_list($USER->id, $params['lane'], $params['contextid'], $params['archived']);
    }

    /**
     * Returns.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(new external_single_structure([
            'id'           => new external_value(PARAM_INT, 'conversation id'),
            'name'         => new external_value(PARAM_TEXT, 'title'),
            'archived'     => new external_value(PARAM_BOOL, 'archived'),
            'timemodified' => new external_value(PARAM_INT, 'last activity'),
        ]));
    }
}
