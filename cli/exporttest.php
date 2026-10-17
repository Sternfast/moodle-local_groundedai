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
 * Export engine test: Excel/CSV/PDF tables, narrative PDF, CSV-injection escaping, overwrite, gating.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');

use local_groundedai\local\conversation;
use local_groundedai\local\exporter;

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
    printf("%-50s %s%s\n", $label, $cond ? 'OK' : 'FAIL', $detail !== '' ? '  (' . $detail . ')' : '');
}

$admin = get_admin();
\core\session\manager::set_user($admin);
$userid = (int) $admin->id;
$context = \context_system::instance();
$conversationid = conversation::create($userid, $context->id, 'staff');

$columns = ['Student', 'Grade', 'Note'];
$rows = [
    ['Ada Lovelace', 95, 'On track'],
    ['Grace Hopper', 88, '=HYPERLINK("http://evil")'], // Formula-injection attempt.
];

// Excel.
$xlsx = exporter::table($conversationid, $userid, 'Term grades', $columns, $rows, 'excel');
gai_assert(
    'excel export created (.xlsx, non-empty)',
    substr($xlsx->get_filename(), -5) === '.xlsx' && $xlsx->get_filesize() > 0,
    $xlsx->get_filename()
);

// CSV + formula-injection escaping.
$csv = exporter::table($conversationid, $userid, 'Term grades csv', $columns, $rows, 'csv');
$csvtext = $csv->get_content();
gai_assert('csv export created (.csv)', substr($csv->get_filename(), -4) === '.csv');
gai_assert('csv has header + data', strpos($csvtext, 'Student') !== false && strpos($csvtext, 'Ada Lovelace') !== false);
gai_assert('csv escapes the formula cell', strpos($csvtext, "'=HYPERLINK") !== false, 'OWASP CSV-injection guard');

// PDF table.
$pdft = exporter::table($conversationid, $userid, 'Term grades pdf', $columns, $rows, 'pdf');
gai_assert(
    'pdf table created (%PDF header)',
    substr($pdft->get_filename(), -4) === '.pdf' && strncmp($pdft->get_content(), '%PDF-', 5) === 0
);

// Narrative PDF, with a script that must be stripped.
$doc = exporter::document(
    $conversationid,
    $userid,
    'Progress summary',
    '<p>Ada is <b>on track</b>.</p><script>alert(1)</script><p>Grace needs support.</p>'
);
$doccontent = $doc->get_content();
gai_assert(
    'narrative pdf created (%PDF header)',
    substr($doc->get_filename(), -4) === '.pdf' && strncmp($doccontent, '%PDF-', 5) === 0
);

// Overwrite: re-exporting the same title replaces rather than duplicates.
$xlsx2 = exporter::table($conversationid, $userid, 'Term grades', $columns, $rows, 'excel');
$count = $DB->count_records_sql(
    "SELECT COUNT(*) FROM {files} WHERE contextid = ? AND component = 'local_groundedai'
       AND filearea = 'export' AND itemid = ? AND filename = ?",
    [$context->id, $conversationid, 'Term grades.xlsx']
);
gai_assert('re-export overwrites (one file per title)', $count === 1, 'files=' . $count);

// Download URL is a pluginfile URL.
$url = exporter::file_url($xlsx);
gai_assert('file_url is a pluginfile URL', strpos($url->out(false), '/pluginfile.php') !== false
    || strpos($url->out(false), 'pluginfile.php') !== false);

// Capability/ownership gate: another user cannot export this conversation.
$denied = false;
try {
    exporter::table($conversationid, $userid + 999999, 'X', $columns, $rows, 'csv');
} catch (\moodle_exception $e) {
    $denied = ($e->errorcode === 'notyours');
}
gai_assert('non-owner export denied (notyours)', $denied);

// Export via the staff tool loop.
$priorexport = get_config('local_groundedai', 'enableexport');

set_config('enableexport', 0, 'local_groundedai');
$off = \local_groundedai\local\staff\tools::dispatch(
    'export_table',
    ['title' => 'T', 'format' => 'csv', 'columns' => $columns, 'rows' => $rows],
    $userid,
    $conversationid
);
gai_assert('export_table tool blocked when disabled', isset($off['error']));

set_config('enableexport', 1, 'local_groundedai');
$ttool = \local_groundedai\local\staff\tools::dispatch(
    'export_table',
    ['title' => 'Class list', 'format' => 'excel', 'columns' => $columns, 'rows' => $rows],
    $userid,
    $conversationid
);
gai_assert(
    'export_table tool returns a download link',
    isset($ttool['url']) && isset($ttool['filename']) && strpos($ttool['url'], 'pluginfile.php') !== false
);

$dtool = \local_groundedai\local\staff\tools::dispatch(
    'export_document',
    ['title' => 'Summary', 'body' => '<p>All good.</p>'],
    $userid,
    $conversationid
);
gai_assert(
    'export_document tool returns a pdf link',
    isset($dtool['url']) && substr($dtool['filename'], -4) === '.pdf'
);

set_config('enableexport', $priorexport === false ? 0 : $priorexport, 'local_groundedai');

// Cleanup.
get_file_storage()->delete_area_files($context->id, 'local_groundedai', 'export', $conversationid);
$DB->delete_records('local_groundedai_conversations', ['id' => $conversationid]);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
