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
 * Add or edit one access grant — a role or a user, with course scope and expiry.
 *
 * The grantee pickers are structural: the role picker is built only from eligible (non-forbidden) roles,
 * so a learner is never an option; the user picker names a specific individual.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_groundedai\form;

use local_groundedai\local\access_manager;
use local_groundedai\local\user_picker;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * The grant editor. customdata: ['type' => 'role'|'user', 'grantid' => int].
 */
class grant_form extends \moodleform {
    /** @var int Above this many courses, the picker switches to the AJAX selector instead of preloading all. */
    const COURSE_PICKER_STATIC_MAX = 2000;

    /**
     * Form definition.
     */
    protected function definition(): void {
        $mform = $this->_form;
        $type = $this->_customdata['type'] ?? access_manager::GRANTEE_ROLE;
        $context = \context_system::instance();

        $mform->addElement('hidden', 'id', $this->_customdata['grantid'] ?? 0);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'type', $type);
        $mform->setType('type', PARAM_ALPHA);

        // Grantee — a role (picked only from eligible roles) or a specific user.
        if ($type === access_manager::GRANTEE_USER) {
            $opts = user_picker::autocomplete_options($context, get_string('grant_user_none', 'local_groundedai'));
            $opts['multiple'] = false;
            $mform->addElement('autocomplete', 'userid', get_string('grant_user', 'local_groundedai'), [], $opts);
            $mform->setType('userid', PARAM_INT);
        } else {
            $roleoptions = [];
            foreach (role_fix_names(access_manager::eligible_roles(), $context, ROLENAME_ORIGINAL) as $role) {
                $roleoptions[$role->id] = $role->localname;
            }
            $mform->addElement(
                'autocomplete',
                'roleid',
                get_string('grant_role', 'local_groundedai'),
                $roleoptions,
                ['multiple' => false, 'noselectionstring' => get_string('grant_role_none', 'local_groundedai')]
            );
            $mform->setType('roleid', PARAM_INT);
        }

        // Course scope.
        $mform->addElement('select', 'coursescope', get_string('grant_scope', 'local_groundedai'), [
            access_manager::SCOPE_ALL      => get_string('scope_all', 'local_groundedai'),
            access_manager::SCOPE_ENROLLED => get_string('scope_enrolled', 'local_groundedai'),
            access_manager::SCOPE_COURSE   => get_string('scope_course', 'local_groundedai'),
        ]);
        $mform->setDefault('coursescope', access_manager::SCOPE_ALL);

        // The selection must stick, so this is a static-option autocomplete (client-side filter, no AJAX) —
        // the picker pattern used across the other plugins. On a very large site, fall back to the AJAX
        // course selector rather than preloading tens of thousands of options.
        $nosel = get_string('grant_course_none', 'local_groundedai');
        if (self::course_count() > self::COURSE_PICKER_STATIC_MAX) {
            $mform->addElement('autocomplete', 'courseids', get_string('grant_course', 'local_groundedai'), [], [
                'ajax'              => 'core/form-course-selector',
                'multiple'          => true,
                'noselectionstring' => $nosel,
            ]);
        } else {
            $mform->addElement(
                'autocomplete',
                'courseids',
                get_string('grant_course', 'local_groundedai'),
                self::course_options(),
                ['multiple' => true, 'noselectionstring' => $nosel]
            );
        }
        $mform->setType('courseids', PARAM_INT);
        $mform->hideIf('courseids', 'coursescope', 'neq', access_manager::SCOPE_COURSE);

        // Expiry — never, a date, or a number of days from now.
        $mform->addElement('select', 'expirytype', get_string('grant_expiry', 'local_groundedai'), [
            'never' => get_string('expiry_never', 'local_groundedai'),
            'date'  => get_string('expiry_date', 'local_groundedai'),
            'days'  => get_string('expiry_days', 'local_groundedai'),
        ]);
        $mform->setDefault('expirytype', 'never');

        $mform->addElement('date_selector', 'expirydate', get_string('expiry_on', 'local_groundedai'));
        $mform->hideIf('expirydate', 'expirytype', 'neq', 'date');

        $mform->addElement('duration', 'expirydays', get_string('expiry_after', 'local_groundedai'), [
            'defaultunit' => DAYSECS,
            'units'       => [DAYSECS, WEEKSECS],
        ]);
        $mform->hideIf('expirydays', 'expirytype', 'neq', 'days');

        // Which data domains this grant may surface (default all on).
        $mform->addElement('header', 'domainsheader', get_string('grant_domains', 'local_groundedai'));
        $mform->setExpanded('domainsheader');
        foreach (access_manager::DOMAINS as $d) {
            $mform->addElement('advcheckbox', 'domain_' . $d, get_string('domain' . $d, 'local_groundedai'));
            $mform->setDefault('domain_' . $d, 1);
        }

        // Sensitive features this grant enables (default off).
        $mform->addElement('header', 'featuresheader', get_string('grant_features', 'local_groundedai'));
        $mform->addElement('advcheckbox', 'feature_email', get_string('participantcontact', 'local_groundedai'));
        $mform->addElement('advcheckbox', 'feature_upload', get_string('enableupload', 'local_groundedai'));
        $mform->addElement('advcheckbox', 'feature_export', get_string('enableexport', 'local_groundedai'));

        $this->add_action_buttons(true, get_string('grant_save', 'local_groundedai'));
    }

    /**
     * Validate the grant.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        $type = $data['type'] ?? access_manager::GRANTEE_ROLE;

        if ($type === access_manager::GRANTEE_USER) {
            if ((int) ($data['userid'] ?? 0) <= 0) {
                $errors['userid'] = get_string('grant_user_required', 'local_groundedai');
            }
        } else {
            $roleid = (int) ($data['roleid'] ?? 0);
            // Structural re-check: the posted role must still be eligible (double guard with the options).
            if ($roleid <= 0 || !array_key_exists($roleid, access_manager::eligible_roles())) {
                $errors['roleid'] = get_string('grant_role_required', 'local_groundedai');
            }
        }

        if (($data['coursescope'] ?? '') === access_manager::SCOPE_COURSE) {
            $courseids = array_filter(array_map('intval', (array) ($data['courseids'] ?? [])), fn($c) => $c > 0);
            if (!$courseids) {
                $errors['courseids'] = get_string('grant_course_required', 'local_groundedai');
            }
        }

        if (($data['expirytype'] ?? 'never') === 'date' && (int) ($data['expirydate'] ?? 0) < usergetmidnight(time())) {
            $errors['expirydate'] = get_string('expiry_past', 'local_groundedai');
        }
        if (($data['expirytype'] ?? 'never') === 'days' && (int) ($data['expirydays'] ?? 0) < DAYSECS) {
            $errors['expirydays'] = get_string('expiry_tooshort', 'local_groundedai');
        }

        return $errors;
    }

    /**
     * How many courses exist (excluding the site course) — decides static options vs the AJAX selector.
     *
     * @return int Course count.
     */
    private static function course_count(): int {
        global $DB;
        return (int) $DB->count_records_select('course', 'id <> ?', [SITEID]);
    }

    /**
     * Build the static course options for the picker: id => "Full name (shortname)".
     *
     * Loaded once per form render and filtered client-side, so the selection sticks and typing
     * narrows instantly without an AJAX round-trip. Site course is excluded; hidden courses are flagged.
     *
     * @return array Course id => label.
     */
    private static function course_options(): array {
        global $DB;
        $out = [];
        $courses = $DB->get_records_select(
            'course',
            'id <> ?',
            [SITEID],
            'fullname ASC',
            'id, fullname, shortname, visible'
        );
        foreach ($courses as $c) {
            $label = format_string($c->fullname);
            $short = trim((string) $c->shortname);
            if ($short !== '' && stripos($c->fullname, $short) === false) {
                $label .= ' (' . format_string($short) . ')';
            }
            if (!$c->visible) {
                $label .= ' ' . get_string('grant_course_hidden', 'local_groundedai');
            }
            $out[(int) $c->id] = $label;
        }
        return $out;
    }
}
