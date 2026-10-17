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
 * Event: an access grant was created, updated or deleted.
 *
 * Part of the governance audit trail. Records the grant id and the grantee by id + the scope/expiry
 * config — never a name or email.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_groundedai\event;

/**
 * An access grant changed.
 */
class grant_changed extends \core\event\base {
    /**
     * A config change, not tied to a single course.
     */
    protected function init(): void {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        // The object the event is about. Core refuses an event that carries an objectid without naming
        // its table, so without this every grant_changed trigger throws and the audit trail stays empty.
        $this->data['objecttable'] = 'local_groundedai_grants';
    }

    /**
     * Map the object id for backup/restore. Grants are site-level configuration, not backed up with a
     * course, so the id is explicitly not mapped.
     *
     * @return array
     */
    public static function get_objectid_mapping(): array {
        return ['db' => 'local_groundedai_grants', 'restore' => \core\event\base::NOT_MAPPED];
    }

    /**
     * The event's human-readable name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('event_grant_changed', 'local_groundedai');
    }

    /**
     * A description of what happened, for the log report.
     *
     * @return string
     */
    public function get_description(): string {
        $action = $this->other['action'] ?? 'changed';
        $grantee = $this->other['grantee'] ?? '';
        return "The user with id '{$this->userid}' {$action} assistant access grant id '{$this->objectid}' "
            . "(grantee: {$grantee}).";
    }
}
