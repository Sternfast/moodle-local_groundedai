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
 * Developer harness: exercise the conversation store end to end.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options] = cli_get_params(['courseid' => 0, 'as' => 0], []);
if (!$options['courseid'] || !$options['as']) {
    cli_writeln('Usage: php convtest.php --courseid=ID --as=USERID');
    exit(0);
}

$userid = (int) $options['as'];
\core\session\manager::set_user(\core_user::get_user($userid, '*', MUST_EXIST));
$contextid = context_course::instance((int) $options['courseid'])->id;

$store = '\local_groundedai\local\conversation';

cli_writeln("contextid=$contextid  userid=$userid");

$cid = $store::create($userid, $contextid, 'staff');
cli_writeln("created conversation id=$cid");

$question = 'Who is enrolled in this course?';
$new = $store::send($cid, $userid, $question);
cli_writeln("send() persisted " . count($new) . " messages:");
foreach ($new as $m) {
    cli_writeln("  [" . $m->role . "] " . \core_text::substr($m->content, 0, 90));
}

$page = $store::get_messages($cid, $userid);
cli_writeln("get_messages: " . count($page['messages']) . " messages, hasmore=" . ($page['hasmore'] ? 'yes' : 'no'));

$store::rename($cid, $userid, 'Renamed thread');
$store::set_archived($cid, $userid, true);
$active = $store::get_list($userid, 'staff', $contextid, false);
$archived = $store::get_list($userid, 'staff', $contextid, true);
cli_writeln("after rename+archive: active=" . count($active) . " archived=" . count($archived));
foreach ($archived as $c) {
    if ($c->id == $cid) {
        cli_writeln("  archived row name='" . $c->name . "'");
    }
}

$store::delete($cid, $userid);
$gone = $GLOBALS['DB']->record_exists('local_groundedai_conversations', ['id' => $cid]);
$msgsgone = !$GLOBALS['DB']->record_exists('local_groundedai_messages', ['conversationid' => $cid]);
cli_writeln("after delete: conversation_exists=" . ($gone ? 'YES(bad)' : 'no')
    . " messages_cleared=" . ($msgsgone ? 'yes' : 'NO(bad)'));
cli_writeln("OK");
