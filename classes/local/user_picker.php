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
 * AJAX user-autocomplete options for admin forms.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_groundedai\local;

/**
 * Builds the options for an mform 'autocomplete' element that searches all site users over AJAX.
 * Admin-only — the core_user/form_user_selector backend requires moodle/user:viewalldetails.
 */
class user_picker {
    /**
     * Options for the autocomplete element.
     *
     * @param \context $context Context used for name/identity formatting.
     * @param string $noselectionstring Placeholder shown when nothing is selected.
     * @return array
     */
    public static function autocomplete_options(\context $context, string $noselectionstring): array {
        return [
            'ajax'              => 'core_user/form_user_selector',
            'multiple'          => true,
            'noselectionstring' => $noselectionstring,
            'valuehtmlcallback' => function ($userid) use ($context) {
                global $OUTPUT;
                // With allowcustom=false, identity fields stay on the {user} table (no joins), so
                // passing get_sql()->selects to get_user() is safe.
                $fields = \core_user\fields::for_name()->with_identity($context, false);
                $record = \core_user::get_user($userid, 'id ' . $fields->get_sql()->selects, IGNORE_MISSING);
                if (!$record) {
                    return false;
                }
                $user = (object) [
                    'id'          => $record->id,
                    'fullname'    => fullname($record, has_capability('moodle/site:viewfullnames', $context)),
                    'extrafields' => [],
                ];
                foreach ($fields->get_required_fields([\core_user\fields::PURPOSE_IDENTITY]) as $extrafield) {
                    $user->extrafields[] = (object) ['name' => $extrafield, 'value' => s($record->$extrafield)];
                }
                return $OUTPUT->render_from_template('core_user/form_user_selector_suggestion', $user);
            },
        ];
    }
}
