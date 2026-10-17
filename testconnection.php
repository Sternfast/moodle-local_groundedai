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
 * Provider connection test — one click to confirm the configured endpoint and key actually work.
 *
 * Sends a tiny request through the same facade the assistant uses and reports success (with the model
 * name and round-trip time) or the exact error, so an admin can verify the API before staff rely on it.
 * The key never leaves the server and is never shown here.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_url(new moodle_url('/local/groundedai/testconnection.php'));
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('conn_title', 'local_groundedai'));
$PAGE->set_heading(get_string('conn_title', 'local_groundedai'));

$settingsurl = new moodle_url('/admin/settings.php', ['section' => 'local_groundedai']);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('conn_title', 'local_groundedai'));

$baseurl = trim((string) get_config('local_groundedai', 'baseurl'));
if ($baseurl === '') {
    echo $OUTPUT->notification(get_string('conn_noendpoint', 'local_groundedai'), 'warning');
} else {
    // Use whichever tier has a model configured; prefer the cheap one for the ping.
    $facade = new \local_groundedai\local\provider_facade();
    $tier = $facade->model_for('cheap') !== '' ? 'cheap' : 'frontier';
    try {
        $start = microtime(true);
        $reply = $facade->chat(
            [['role' => 'user', 'content' => 'Reply with the single word: ok']],
            $tier,
            ['max_tokens' => 5]
        );
        $ms = (int) round((microtime(true) - $start) * 1000);
        $model = $reply['model'] !== '' ? $reply['model'] : $facade->model_for($tier);
        echo $OUTPUT->notification(
            get_string('conn_ok', 'local_groundedai', (object) ['model' => s($model), 'ms' => $ms]),
            'success'
        );
    } catch (\Throwable $e) {
        echo $OUTPUT->notification(get_string('conn_fail', 'local_groundedai', s($e->getMessage())), 'error');
    }
}

echo html_writer::div(html_writer::link(
    $settingsurl,
    get_string('conn_back', 'local_groundedai'),
    ['class' => 'btn btn-secondary']
));
echo $OUTPUT->footer();
