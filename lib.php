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
 * Plugin callbacks.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Add the staff assistant link to a course's navigation (for anyone the access rules allow).
 *
 * @param navigation_node $navigation The course navigation node.
 * @param stdClass $course The course.
 * @param context_course $context The course context.
 */
function local_groundedai_extend_navigation_course($navigation, $course, $context) {
    global $USER;

    if (
        get_config('local_groundedai', 'enablestaff')
            && \local_groundedai\local\access_manager::can_use($USER->id)
    ) {
        $navigation->add(
            get_string('openstaffassistant', 'local_groundedai'),
            new moodle_url('/local/groundedai/staff.php', ['id' => $course->id]),
            navigation_node::TYPE_SETTING,
            null,
            'groundedaistaff',
            new pix_icon('i/report', '')
        );
    }
}

/**
 * Declare the per-user preferences this plugin writes, so core's set-preference web service accepts
 * them: whether to hide the floating launcher, and the user's own answer-style tuning text.
 *
 * @return array
 */
function local_groundedai_user_preferences(): array {
    return [
        'local_groundedai_hidelauncher' => [
            'null'               => NULL_NOT_ALLOWED,
            'default'            => 0,
            'type'               => PARAM_BOOL,
            'permissioncallback' => [core_user::class, 'is_current_user'],
        ],
        'local_groundedai_tuning_staff' => [
            'null'               => NULL_NOT_ALLOWED,
            'default'            => '',
            'type'               => PARAM_TEXT,
            'permissioncallback' => [core_user::class, 'is_current_user'],
        ],
    ];
}

/**
 * Add a "Grounded AI assistant" entry to the current user's Preferences page.
 *
 * @param navigation_node $navigation The user-settings navigation node.
 * @param stdClass $user The user whose preferences are shown.
 * @param context_user $usercontext The user context.
 * @param stdClass $course The current course.
 * @param context_course $coursecontext The current course context.
 */
function local_groundedai_extend_navigation_user_settings($navigation, $user, $usercontext, $course, $coursecontext) {
    global $USER;

    if (
        (int) $user->id !== (int) $USER->id
            || !get_config('local_groundedai', 'enablestaff')
            || !\local_groundedai\local\access_manager::can_use($USER->id)
    ) {
        return;
    }
    $navigation->add(
        get_string('mypreferences', 'local_groundedai'),
        new moodle_url('/local/groundedai/preferences.php'),
        navigation_node::TYPE_SETTING,
        null,
        'groundedaiprefs'
    );
}

/**
 * Serve generated export files. Gated by the export capability and conversation ownership.
 *
 * @param stdClass $course Course (or site) record.
 * @param stdClass $cm Course module (unused here).
 * @param context $context The file's context.
 * @param string $filearea The requested file area.
 * @param array $args itemid, then the path and filename.
 * @param bool $forcedownload Whether to force download.
 * @param array $options Serving options.
 * @return bool false if not served (lets Moodle send a 404).
 */
function local_groundedai_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $DB, $USER;

    if ($filearea !== 'export') {
        return false;
    }
    require_login();

    $itemid = (int) array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    // The export belongs to one conversation owned by one user.
    $conv = $DB->get_record('local_groundedai_conversations', ['id' => $itemid], 'id, userid, contextid');
    if (!$conv) {
        return false;
    }
    if ((int) $conv->userid !== (int) $USER->id && !is_siteadmin()) {
        return false;
    }
    require_capability('local/groundedai:export', $context);

    $file = get_file_storage()->get_file(
        $context->id,
        'local_groundedai',
        'export',
        $itemid,
        $filepath,
        $filename
    );
    if (!$file || $file->is_directory()) {
        return false;
    }
    send_stored_file($file, 0, 0, $forcedownload, $options);
}
