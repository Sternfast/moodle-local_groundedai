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
 * End-to-end ingestion test: upload (draft) -> store -> queue -> task -> extract -> read -> delete.
 *
 * Creates a throwaway staff conversation, exercises the pipeline, then cleans up after itself.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');

use local_groundedai\local\attachment_manager;
use local_groundedai\local\conversation;
use local_groundedai\local\extractor;
use local_groundedai\task\extract_text;

global $DB;

$pass = 0;
$fail = 0;
/**
 * Tiny assertion helper.
 *
 * @param string $label
 * @param bool $cond
 * @param string $detail
 */
function gai_assert(string $label, bool $cond, string $detail = '') {
    global $pass, $fail;
    $cond ? $pass++ : $fail++;
    printf("%-46s %s%s\n", $label, $cond ? 'OK' : 'FAIL', $detail !== '' ? '  (' . $detail . ')' : '');
}

// Acting user: the site admin (userid is not guest, always has access).
$admin = get_admin();
\core\session\manager::set_user($admin);   // The draft-area API needs a logged-in, non-guest user.
$userid = (int) $admin->id;
$context = \context_system::instance();

// Turn on upload for the duration of the test so the attachment tools register.
$priorupload = get_config('local_groundedai', 'enableupload');
set_config('enableupload', 1, 'local_groundedai');

// Build a staff conversation to attach to.
$conversationid = conversation::create($userid, $context->id, 'staff');

// Helper: upload a byte string as a draft file, then attach it.
$uploadctx = \context_user::instance($userid);
$fs = get_file_storage();
// Push bytes through a user draft area and into the conversation as an attachment; returns the attach id.
$attach = function (string $filename, string $bytes) use ($fs, $uploadctx, $conversationid, $userid): int {
    $draftitemid = file_get_unused_draft_itemid();
    $fs->create_file_from_string((object) [
        'contextid' => $uploadctx->id, 'component' => 'user', 'filearea' => 'draft',
        'itemid' => $draftitemid, 'filepath' => '/', 'filename' => $filename,
    ], $bytes);
    return attachment_manager::add_file($conversationid, $userid, $draftitemid);
};

// Fixtures.
$xlsxpath = make_request_directory() . '/r.xlsx';
$w = new \OpenSpout\Writer\XLSX\Writer();
$w->openToFile($xlsxpath);
$w->addRow(\OpenSpout\Common\Entity\Row::fromValues(['Student', 'Grade']));
$w->addRow(\OpenSpout\Common\Entity\Row::fromValues(['Ada Lovelace', 95]));
$w->close();
$xlsxbytes = file_get_contents($xlsxpath);

$docxbytes = (function () {
    $path = make_request_directory() . '/d.docx';
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString(
        'word/document.xml',
        '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:body><w:p><w:r><w:t>Term report narrative.</w:t></w:r></w:p></w:body></w:document>'
    );
    $zip->close();
    return file_get_contents($path);
})();

$macrobytes = (function () {
    $path = make_request_directory() . '/m.xlsx';
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook/>');
    $zip->addFromString('xl/vbaProject.bin', "\x00macro");
    $zip->close();
    return file_get_contents($path);
})();

// Attach: xlsx, docx, a duplicate of the xlsx (dedupe), and a macro file (reject).
$idxlsx  = $attach('roster.xlsx', $xlsxbytes);
$iddocx  = $attach('report.docx', $docxbytes);
$iddup   = $attach('roster-copy.xlsx', $xlsxbytes);
$idmacro = $attach('evil.xlsx', $macrobytes);

gai_assert(
    '4 attachments created, all pending',
    $DB->count_records('local_groundedai_attach', ['conversationid' => $conversationid, 'status' => 'pending']) === 4
);
gai_assert(
    '4 extract tasks queued',
    $DB->count_records('task_adhoc', ['classname' => '\\local_groundedai\\task\\extract_text']) >= 4
);

// Run the extraction tasks in order (simulate cron processing our attachments).
foreach ([$idxlsx, $iddocx, $iddup, $idmacro] as $aid) {
    extract_text::instance($aid)->execute();
}

$get = function (int $aid) use ($DB) {
    return $DB->get_record('local_groundedai_attach', ['id' => $aid]);
};

$rx = $get($idxlsx);
gai_assert(
    'xlsx extracted (done, has text)',
    $rx->status === extractor::DONE && (int) $rx->chars > 0 && strpos($rx->content, 'Ada Lovelace') !== false,
    $rx->status . '/' . $rx->chars
);

$rd = $get($iddocx);
gai_assert(
    'docx extracted (done, has text)',
    $rd->status === extractor::DONE && strpos($rd->content, 'Term report narrative') !== false,
    $rd->status . '/' . $rd->chars
);

$rdup = $get($iddup);
gai_assert(
    'duplicate xlsx reused prior extraction',
    $rdup->status === extractor::DONE && $rdup->content === $rx->content && $rdup->chars === $rx->chars
);

$rm = $get($idmacro);
gai_assert(
    'macro xlsx rejected',
    $rm->status === extractor::REJECTED && $rm->content === null,
    $rm->status
);

// Read-chunk tool path.
$chunk = attachment_manager::read_chunk($idxlsx, $userid, 0, 10);
gai_assert(
    'read_chunk returns first slice + hasmore',
    $chunk['status'] === 'done' && \core_text::strlen($chunk['text']) === 10
    && $chunk['hasmore'] === true && $chunk['total'] === (int) $rx->chars
);

$tail = attachment_manager::read_chunk($idxlsx, $userid, (int) $rx->chars - 3, 50);
gai_assert(
    'read_chunk tail has no more',
    $tail['status'] === 'done' && $tail['hasmore'] === false
);

// Listing (metadata only, never content).
$list = attachment_manager::list_for($conversationid, $userid);
gai_assert(
    'list_for returns 4 rows without content',
    count($list) === 4 && !property_exists($list[0], 'content')
);

// Staff tool-loop integration.
$schemas = \local_groundedai\local\staff\tools::schemas($conversationid);
$toolnames = array_map(fn($s) => $s['function']['name'], $schemas);
gai_assert(
    'schemas include attachment tools when upload enabled',
    in_array('list_attachments', $toolnames, true) && in_array('read_attachment', $toolnames, true)
);

$listtool = \local_groundedai\local\staff\tools::dispatch('list_attachments', [], $userid, $conversationid);
gai_assert(
    'list_attachments tool returns the files',
    isset($listtool['attachments']) && count($listtool['attachments']) === 4
);

$readtool = \local_groundedai\local\staff\tools::dispatch(
    'read_attachment',
    ['id' => $idxlsx],
    $userid,
    $conversationid
);
gai_assert(
    'read_attachment tool returns labelled untrusted content',
    ($readtool['status'] ?? '') === 'done'
        && isset($readtool['untrusted_file_content'])
    && strpos($readtool['untrusted_file_content'], 'Ada Lovelace') !== false
);

// Cross-conversation scoping: a different conversation cannot read this one's file.
$otherconv = conversation::create($userid, $context->id, 'staff');
$crossread = \local_groundedai\local\staff\tools::dispatch(
    'read_attachment',
    ['id' => $idxlsx],
    $userid,
    $otherconv
);
gai_assert(
    'read_attachment rejects an id from another conversation',
    isset($crossread['error'])
);
$DB->delete_records('local_groundedai_conversations', ['id' => $otherconv]);

// Ownership guard: a different user cannot read.
$otheruser = $userid + 999999;
$denied = false;
try {
    attachment_manager::read_chunk($idxlsx, $otheruser, 0, 10);
} catch (\moodle_exception $e) {
    $denied = ($e->errorcode === 'notyours');
}
gai_assert('non-owner read denied (notyours)', $denied);

// Delete removes the stored file and the row.
$fileid = (int) $rx->fileid;
attachment_manager::delete($idxlsx, $userid);
gai_assert(
    'delete removes row',
    !$DB->record_exists('local_groundedai_attach', ['id' => $idxlsx])
);
gai_assert(
    'delete removes stored file',
    get_file_storage()->get_file_by_id($fileid) === false
);

// Cleanup: remaining attachments, their files, the conversation, and queued tasks.
foreach ([$iddocx, $iddup, $idmacro] as $aid) {
    try {
        attachment_manager::delete($aid, $userid);
    } catch (\Throwable $e) {
        cli_writeln("  (cleanup: attachment $aid already gone)");
    }
}
$DB->delete_records('local_groundedai_conversations', ['id' => $conversationid]);
$DB->delete_records('task_adhoc', ['classname' => '\\local_groundedai\\task\\extract_text']);
// Restore the upload setting to its prior value.
set_config('enableupload', $priorupload === false ? 0 : $priorupload, 'local_groundedai');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
