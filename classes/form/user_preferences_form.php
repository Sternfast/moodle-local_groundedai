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
 * The per-user assistant preferences form.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_groundedai\form;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/formslib.php');

/**
 * Lets a staff member show/hide the floating launcher and add their own answer-style instructions.
 */
class user_preferences_form extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement(
            'advcheckbox',
            'showlauncher',
            get_string('showlauncher', 'local_groundedai'),
            get_string('showlauncher_label', 'local_groundedai')
        );
        $mform->setDefault('showlauncher', $this->_customdata['showlauncher']);

        $default = trim((string) ($this->_customdata['defaulttuning'] ?? ''));
        if ($default !== '') {
            $mform->addElement(
                'static',
                'defaulttuninginfo',
                get_string('defaulttuningstaff', 'local_groundedai'),
                \html_writer::tag('pre', s($default), ['class' => 'gai-tuning-default'])
            );
        }

        $mform->addElement(
            'textarea',
            'tuning',
            get_string('tuningstaff', 'local_groundedai'),
            ['rows' => 6, 'cols' => 60, 'maxlength' => 1300]
        );
        $mform->setType('tuning', PARAM_TEXT);
        $mform->addHelpButton('tuning', 'tuningstaff', 'local_groundedai');
        $mform->setDefault('tuning', $this->_customdata['tuning']);

        $this->add_action_buttons(true, get_string('savechanges'));
    }
}
