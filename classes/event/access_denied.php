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
 * Event: the assistant refused a request.
 *
 * Logged when access is denied (the user is not on the staff allow-list) or, more seriously, when the
 * assistant HALTS because a forbidden role — a student, guest or learner — was found on the allow-list.
 * The halt reason 'forbidden_role_halt' is the visible side of the hard student-exclusion invariant: an
 * institution reviewing the log can see the moment the assistant stopped itself rather than risk a
 * learner reaching student records.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_groundedai\event;

/**
 * The staff assistant refused a request.
 */
class access_denied extends \core\event\base {
    /**
     * Read-only, not tied to a single record.
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
        return get_string('event_access_denied', 'local_groundedai');
    }

    /**
     * A description of what happened, for the log report.
     *
     * @return string
     */
    public function get_description(): string {
        $reason = $this->other['reason'] ?? 'denied';
        if ($reason === 'forbidden_role_halt') {
            return "The AI teacher assistant HALTED for the user with id '{$this->userid}': a forbidden role "
                . "(student, guest or learner) was present on the staff allow-list.";
        }
        return "The AI teacher assistant denied access to the user with id '{$this->userid}' (reason: "
            . "{$reason}).";
    }
}
