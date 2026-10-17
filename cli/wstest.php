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
 * Developer harness: exercise the chat external functions (direct execute) end to end.
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
    cli_writeln('Usage: php wstest.php --courseid=ID --as=USERID');
    exit(0);
}
\core\session\manager::set_user(\core_user::get_user((int) $options['as'], '*', MUST_EXIST));
$ctxid = context_course::instance((int) $options['courseid'])->id;
$ns = '\local_groundedai\external\\';

$cid = call_user_func([$ns . 'create_conversation', 'execute'], 'staff', $ctxid)['id'];
cli_writeln("create_conversation -> id=$cid");

$q = 'Who is enrolled here?';
$s = call_user_func([$ns . 'send_message', 'execute'], $cid, $q);
cli_writeln('send_message -> ' . count($s['messages']) . ' messages: '
    . implode(' | ', array_map(fn($m) => $m->role . ':' . \core_text::substr($m->content, 0, 40), $s['messages'])));

$list = call_user_func([$ns . 'get_conversations', 'execute'], 'staff', $ctxid, false);
cli_writeln('get_conversations(active) -> ' . count($list) . " (name='" . ($list[0]->name ?? '') . "')");

$m = call_user_func([$ns . 'get_messages', 'execute'], $cid, 0, 30);
cli_writeln('get_messages -> ' . count($m['messages']) . ' messages, hasmore=' . ($m['hasmore'] ? 'yes' : 'no'));

call_user_func([$ns . 'rename_conversation', 'execute'], $cid, 'WS renamed');
call_user_func([$ns . 'archive_conversation', 'execute'], $cid, true);
$arch = call_user_func([$ns . 'get_conversations', 'execute'], 'staff', $ctxid, true);
$name = 'NOT FOUND (bad)';
foreach ($arch as $c) {
    if ($c->id == $cid) {
        $name = $c->name;
    }
}
cli_writeln("archive+rename -> archived row name='$name'");

call_user_func([$ns . 'delete_conversation', 'execute'], $cid);
cli_writeln('delete_conversation -> done');
cli_writeln('OK');
