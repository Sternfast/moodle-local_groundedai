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
 * Hook callbacks.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_groundedai;

use core\hook\output\before_footer_html_generation;

/**
 * Output hook callbacks.
 */
class hook_callbacks {
    /**
     * Inject the staff assistant launcher (a floating button) on course pages.
     *
     * Runs at footer time so $PAGE->context / ->cm / ->pagetype are reliable: the button can tell the
     * assistant which course and activity the staff member is viewing, so "this quiz/activity" resolves
     * without them restating it. The button is suppressed for anyone who turned it off in their
     * preferences, and the conversation stays course-scoped (the page is only an advisory hint).
     *
     * @param before_footer_html_generation $hook The hook.
     */
    public static function add_launcher(before_footer_html_generation $hook): void {
        global $PAGE, $USER;

        if (!get_config('local_groundedai', 'enablestaff')) {
            return;
        }
        // Not on the plugin's own pages (staff.php / governance.php / grant_edit.php / preferences.php)
        // or admin/report chrome.
        if (strpos((string) $PAGE->pagetype, 'local-groundedai-') === 0) {
            return;
        }
        $skiplayouts = ['admin', 'report', 'maintenance', 'print', 'embedded', 'secure', 'redirect', 'popup', 'frametop'];
        if (in_array($PAGE->pagelayout, $skiplayouts, true)) {
            return;
        }
        if (!\local_groundedai\local\access_manager::can_use($USER->id)) {
            return;
        }
        // The viewer's own choice to hide the button (a per-user preference, so it follows them).
        if (get_user_preferences('local_groundedai_hidelauncher', 0)) {
            return;
        }
        // Only on a real course/activity page, never the site home.
        $coursectx = $PAGE->context ? $PAGE->context->get_course_context(false) : false;
        if (!$coursectx || (int) $coursectx->instanceid === (int) SITEID) {
            return;
        }

        $cmid = isset($PAGE->cm->id) ? (int) $PAGE->cm->id : 0;
        $pagetype = (string) $PAGE->pagetype;

        // Upload is optional and grant-gated; resolve the "Upload a file" repository so the popup
        // composer can attach files, exactly as the full page does (staff.php). Fail-closed: if the
        // repo can't be resolved, the "+" attach control stays hidden.
        $canupload = \local_groundedai\local\access_manager::feature_enabled($USER->id, 'upload');
        $repoid = 0;
        $usercontextid = \context_user::instance($USER->id)->id;
        $draftitemid = 0;
        if ($canupload) {
            require_once($GLOBALS['CFG']->dirroot . '/repository/lib.php');
            $repos = \repository::get_instances(['type' => 'upload', 'currentcontext' => $coursectx]);
            $uploadrepo = reset($repos);
            $repoid = $uploadrepo ? $uploadrepo->id : 0;
            $draftitemid = file_get_unused_draft_itemid();
            $canupload = $canupload && $repoid;
        }

        $PAGE->requires->js_call_amd('local_groundedai/shell_popup', 'initStaff', [[
            'contextid'     => (int) $coursectx->id,
            'courseid'      => (int) $coursectx->instanceid,
            'cmid'          => $cmid,
            'pagetype'      => $pagetype,
            'canupload'     => (bool) $canupload,
            'repoid'        => (int) $repoid,
            'usercontextid' => (int) $usercontextid,
            'draftitemid'   => (int) $draftitemid,
            'maxbytes'      => \local_groundedai\local\attachment_manager::MAX_BYTES,
        ]]);

        // A small round icon FAB — quick, always-there access that stays put when the chat is closed.
        // It can be turned off from the user's own preferences (deliberate, not an accidental click).
        $label = get_string('openstaffassistant', 'local_groundedai');
        $icon = '<svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true" focusable="false">'
            . '<path fill="currentColor" d="M20 2H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4v3.5a.5.5 0 0 0 .8.4'
            . 'L14 18h6a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2z"/></svg>';
        $hook->add_html('<button type="button" class="btn btn-primary gai-launch gai-launch-icon"'
            . ' data-gai-launch="1" aria-haspopup="dialog" aria-expanded="false"'
            . ' aria-label="' . s($label) . '" title="' . s($label) . '">' . $icon . '</button>');
    }
}
