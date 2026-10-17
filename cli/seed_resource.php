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
 * Seed a File resource (HTML study notes) into a course so read_material can be demoed.
 *
 * Usage: php seed_resource.php [courseid]
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/course/modlib.php');

global $DB, $CFG;

$courseid = isset($argv[1]) ? (int) $argv[1] : 4;
$course = get_course($courseid);
$admin = get_admin();
\core\session\manager::set_user($admin);

$name = 'Geometry study notes';
// Skip if a resource of this name already exists.
if ($DB->record_exists('resource', ['course' => $courseid, 'name' => $name])) {
    echo "resource already exists\n";
    exit(0);
}

$html = '<html><body><h1>Geometry Study Notes</h1>'
    . '<p>The interior angles of a triangle always sum to 180 degrees. '
    . 'The Pythagorean theorem states that a squared plus b squared equals c squared for a right triangle. '
    . 'Two triangles are congruent when their corresponding sides and angles are equal. '
    . 'The area of a triangle is one half times base times height.</p></body></html>';

$usercontext = context_user::instance($admin->id);
$draftid = file_get_unused_draft_itemid();
get_file_storage()->create_file_from_string((object) [
    'contextid' => $usercontext->id, 'component' => 'user', 'filearea' => 'draft',
    'itemid' => $draftid, 'filepath' => '/', 'filename' => 'geometry-notes.html',
], $html);

$moduleinfo = (object) [
    'modulename'                => 'resource',
    'module'                    => $DB->get_field('modules', 'id', ['name' => 'resource'], MUST_EXIST),
    'course'                    => $course->id,
    'section'                   => 1,
    'visible'                   => 1,
    'name'                      => $name,
    'intro'                     => 'Reference notes for the geometry unit.',
    'introformat'               => FORMAT_HTML,
    'files'                     => $draftid,
    'display'                   => 0,
    'printintro'                => 1,
    'showsize'                  => 0,
    'showtype'                  => 0,
    'showdate'                  => 0,
    'cmidnumber'                => '',
    'groupmode'                 => 0,
    'groupingid'                => 0,
    'visibleoncoursepage'       => 1,
    'completion'                => 0,
    'completionview'            => 0,
    'completionexpected'        => 0,
    'availabilityconditionsjson' => null,
];

$moduleinfo = add_moduleinfo($moduleinfo, $course);
echo "created resource '{$name}' cmid {$moduleinfo->coursemodule}\n";
rebuild_course_cache($courseid, true);
echo "done\n";
