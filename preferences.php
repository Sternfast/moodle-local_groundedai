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
 * Per-user assistant preferences: show/hide the launcher, and personal answer-style instructions.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');

use local_groundedai\local\access_manager;
use local_groundedai\form\user_preferences_form;

require_login();
if (isguestuser()) {
    throw new moodle_exception('noguest');
}
if (!get_config('local_groundedai', 'enablestaff') || !access_manager::can_use($USER->id)) {
    throw new moodle_exception('noaccess', 'local_groundedai');
}

$url = new moodle_url('/local/groundedai/preferences.php');
$PAGE->set_url($url);
$PAGE->set_context(context_user::instance($USER->id));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('mypreferences', 'local_groundedai'));
$PAGE->set_heading(fullname($USER));

$form = new user_preferences_form(null, [
    'showlauncher'  => get_user_preferences('local_groundedai_hidelauncher', 0) ? 0 : 1,
    'tuning'        => get_user_preferences('local_groundedai_tuning_staff', ''),
    'defaulttuning' => (string) get_config('local_groundedai', 'defaulttuningstaff'),
]);

if ($form->is_cancelled()) {
    redirect(new moodle_url('/user/preferences.php'));
} else if ($data = $form->get_data()) {
    set_user_preference('local_groundedai_hidelauncher', empty($data->showlauncher) ? 1 : 0);
    // Cap within the user_preferences.value column limit (1333 chars) so a long entry can't error on save.
    $tuning = core_text::substr(clean_param($data->tuning ?? '', PARAM_TEXT), 0, 1300);
    if (trim($tuning) === '') {
        unset_user_preference('local_groundedai_tuning_staff');
    } else {
        set_user_preference('local_groundedai_tuning_staff', $tuning);
    }
    redirect($url, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('mypreferences', 'local_groundedai'));
$form->display();
echo $OUTPUT->footer();
