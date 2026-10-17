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
 * Developer harness: ask the staff assistant from the command line.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params(
    ['courseid' => 0, 'question' => '', 'as' => 0, 'help' => false],
    ['h' => 'help']
);

if ($options['help'] || !$options['courseid'] || trim((string) $options['question']) === '') {
    cli_writeln("Grounded AI — ask the staff assistant (developer harness).");
    cli_writeln("Usage: php staffask.php --courseid=ID --question=\"...\" [--as=USERID]");
    exit(0);
}

$userid = $options['as'] ? (int) $options['as'] : get_admin()->id;
\core\session\manager::set_user(\core_user::get_user($userid, '*', MUST_EXIST));

$res = \local_groundedai\local\staff_assistant::answer(
    (int) $options['courseid'],
    $userid,
    (string) $options['question']
);

cli_writeln("\n--- ANSWER ---");
cli_writeln($res['answer']);
cli_writeln("\ntools used: " . ($res['toolsused'] ? implode(', ', $res['toolsused']) : '(none)')
    . "  model: " . ($res['model'] ?? '-')
    . "  usage: " . ($res['usage'] ? json_encode($res['usage']) : '-'));
