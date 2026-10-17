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
 * Staff lane: the read-only staff assistant, as a full-page chat.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');

use local_groundedai\local\access_manager;

$courseid = required_param('id', PARAM_INT);

$course = get_course($courseid);
require_login($course);
$context = context_course::instance($courseid);

if (!get_config('local_groundedai', 'enablestaff')) {
    throw new moodle_exception('staffdisabled', 'local_groundedai');
}
if (!access_manager::can_use($USER->id)) {
    throw new moodle_exception('noaccess', 'local_groundedai');
}

$PAGE->set_url(new moodle_url('/local/groundedai/staff.php', ['id' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('staffassistant', 'local_groundedai'));
$PAGE->set_heading($course->fullname);
$PAGE->add_body_class('local-groundedai-staffpage');

// Upload is optional and grant-gated; resolve the "Upload a file" repository for the composer.
$canupload = \local_groundedai\local\access_manager::feature_enabled($USER->id, 'upload');
$repoid = 0;
$usercontextid = context_user::instance($USER->id)->id;
$draftitemid = 0;
if ($canupload) {
    require_once($CFG->dirroot . '/repository/lib.php');
    $repos = repository::get_instances(['type' => 'upload', 'currentcontext' => $context]);
    $uploadrepo = reset($repos);
    $repoid = $uploadrepo ? $uploadrepo->id : 0;
    $draftitemid = file_get_unused_draft_itemid();
    $canupload = $canupload && $repoid;
}

echo $OUTPUT->header();

// Height/overflow are owned by styles.css (.local-groundedai-staffmount) so the card fits the
// viewport and only the chat panel scrolls — no inline sizing that would reintroduce a page scroll.
echo html_writer::div('', 'local-groundedai-staffmount', [
    'data-region'  => 'gai-staffmount',
]);

$PAGE->requires->js_call_amd('local_groundedai/shell_staff', 'init', [[
    'contextid'     => (int) $context->id,
    'canupload'     => (bool) $canupload,
    'repoid'        => (int) $repoid,
    'usercontextid' => (int) $usercontextid,
    'draftitemid'   => (int) $draftitemid,
    'maxbytes'      => \local_groundedai\local\attachment_manager::MAX_BYTES,
]]);

echo $OUTPUT->footer();
