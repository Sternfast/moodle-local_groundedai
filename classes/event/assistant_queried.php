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
 * Event: a staff member queried the AI teacher assistant.
 *
 * This is the plugin's access-accountability trail — "who used the assistant, when, in which course".
 * It records no prompt or response content (that stays out of logs, per the telemetry-metadata-only
 * policy); only the actor, the course context and the tools the turn used. Moodle's standard logstore
 * persists it, so the institution's existing log report and retention govern it.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_groundedai\event;

/**
 * The staff assistant answered a query for the acting user.
 */
class assistant_queried extends \core\event\base {
    /**
     * Read-only, course-level, not tied to a single record.
     */
    protected function init(): void {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * The event's human-readable name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('event_assistant_queried', 'local_groundedai');
    }

    /**
     * A description of what happened, for the log report.
     *
     * @return string
     */
    public function get_description(): string {
        $tools = '';
        if (!empty($this->other['tools'])) {
            $tools = " using: " . $this->other['tools'];
        }
        return "The user with id '{$this->userid}' queried the AI teacher assistant in the course with id "
            . "'{$this->courseid}'{$tools}.";
    }
}
