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
 * Exercise the extractor across the happy path and the safety edge cases.
 *
 * Generates fixtures on the fly (no binary files committed) and asserts the
 * (type, status) each one should produce.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');

use local_groundedai\local\extractor;

$dir = make_request_directory();

/**
 * Write a string to a fixture file and return its path.
 *
 * @param string $dir
 * @param string $name
 * @param string $content
 * @return string
 */
function gai_fixture(string $dir, string $name, string $content): string {
    $path = $dir . '/' . $name;
    file_put_contents($path, $content);
    return $path;
}

/**
 * Build a zip fixture from [memberName => bytes] and return its path.
 *
 * @param string $dir
 * @param string $name
 * @param array $members
 * @return string
 */
function gai_zip(string $dir, string $name, array $members): string {
    $path = $dir . '/' . $name;
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($members as $member => $bytes) {
        $zip->addFromString($member, $bytes);
    }
    $zip->close();
    return $path;
}

// Fixtures.

$fixtures = [];

// Plain text with a UTF-8 BOM.
$fixtures[] = ['a.txt', gai_fixture($dir, 'a.txt', "\xEF\xBB\xBFHello world.\nSecond line."), 'txt', extractor::DONE];

// XLSX via the bundled OpenSpout writer.
$xlsxpath = $dir . '/b.xlsx';
$writer = new \OpenSpout\Writer\XLSX\Writer();
$writer->openToFile($xlsxpath);
$writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(['Name', 'Grade']));
$writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(['Ada', 95]));
$writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(['Grace', 88]));
$writer->close();
$fixtures[] = ['b.xlsx', $xlsxpath, 'xlsx', extractor::DONE];

// DOCX (minimal OOXML).
$docxxml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
    . '<w:p><w:r><w:t>Hello from the docx.</w:t></w:r></w:p>'
    . '<w:p><w:r><w:t>Second paragraph here.</w:t></w:r></w:p>'
    . '</w:body></w:document>';
$fixtures[] = ['c.docx', gai_zip($dir, 'c.docx', [
    '[Content_Types].xml' => '<?xml version="1.0"?><Types/>',
    'word/document.xml' => $docxxml,
]), 'docx', extractor::DONE];

// PPTX (minimal OOXML, two slides).
$slide = function (string $t) {
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
        . 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
        . '<p:cSld><p:spTree><a:p><a:r><a:t>' . $t . '</a:t></a:r></a:p></p:spTree></p:cSld></p:sld>';
};
$fixtures[] = ['d.pptx', gai_zip($dir, 'd.pptx', [
    'ppt/presentation.xml' => '<?xml version="1.0"?><p:presentation/>',
    'ppt/slides/slide1.xml' => $slide('First slide text'),
    'ppt/slides/slide2.xml' => $slide('Second slide text'),
]), 'pptx', extractor::DONE];

// Plain zip of two text files.
$fixtures[] = ['e.zip', gai_zip($dir, 'e.zip', [
    'one.txt' => 'content of file one',
    'notes/two.txt' => 'content of file two',
]), 'zip', extractor::DONE];

// PDF with a real text layer, read by the bundled pure-PHP parser (no poppler needed).
require_once($CFG->libdir . '/pdflib.php');
$pdf = new \pdf();
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->AddPage();
$pdf->writeHTML('<p>Grounded AI PDF extraction works.</p>');
$fixtures[] = ['f.pdf', gai_fixture($dir, 'f.pdf', $pdf->Output('', 'S')), 'pdf', extractor::DONE];

// EDGE: path traversal inside a zip.
$fixtures[] = ['trav.zip', gai_zip($dir, 'trav.zip', [
    '../escape.txt' => 'should never be read',
]), '', extractor::REJECTED];

// EDGE: macro project inside an otherwise-valid xlsx (content-based macro reject).
$fixtures[] = ['macro.xlsx', gai_zip($dir, 'macro.xlsx', [
    'xl/workbook.xml' => '<?xml version="1.0"?><workbook/>',
    'xl/vbaProject.bin' => "\x00\x01\x02macro",
]), '', extractor::REJECTED];

// EDGE: DOCTYPE smuggled into docx body (XXE guard).
$fixtures[] = ['xxe.docx', gai_zip($dir, 'xxe.docx', [
    'word/document.xml' => '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a "boom">]>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:body><w:p><w:r><w:t>&a;</w:t></w:r></w:p></w:body></w:document>',
]), '', extractor::REJECTED];

// EDGE: zip bomb by ratio (20 MB of zeros, trivially compressible).
$fixtures[] = ['bomb.zip', gai_zip($dir, 'bomb.zip', [
    'zeros.txt' => str_repeat("\0", 20 * 1024 * 1024),
]), '', extractor::REJECTED];

// EDGE: OOXML decompression bomb — a highly-compressible document.xml. Must be rejected by the
// per-part size/ratio guard BEFORE getFromName() would allocate it (the OOM path).
$bombxml = '<?xml version="1.0"?>'
    . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
    . '<w:p><w:r><w:t>' . str_repeat('A', 20 * 1024 * 1024) . '</w:t></w:r></w:p>'
    . '</w:body></w:document>';
$fixtures[] = ['bomb.docx', gai_zip($dir, 'bomb.docx', [
    'word/document.xml' => $bombxml,
]), '', extractor::REJECTED];

// EDGE: Latin-1 (non-UTF-8) text must be converted, not silently wiped.
$fixtures[] = ['latin1.txt', gai_fixture($dir, 'latin1.txt', "Caf\xe9 r\xe9sum\xe9 na\xefve"), 'txt', extractor::DONE];

// HTML (SCORM pages / embedded content): visible text only, scripts and styles stripped.
$fixtures[] = ['notes.html', gai_fixture(
    $dir,
    'notes.html',
    '<!DOCTYPE html><html><head><title>t</title><style>h1{color:red}</style>'
    . '<script>alert("x")</script></head><body><h1>Study Notes</h1>'
    . '<p>Photosynthesis converts light into chemical energy.</p></body></html>'
),
    'html', extractor::DONE];

// EDGE: macro-enabled extension refused up front.
$fixtures[] = ['sheet.xlsm', gai_zip($dir, 'sheet.xlsm', [
    'xl/workbook.xml' => '<?xml version="1.0"?><workbook/>',
]), '', extractor::REJECTED];

// EDGE: unknown binary → noext.
$fixtures[] = ['mystery.bin',
    gai_fixture($dir, 'mystery.bin', "\x89\x7f\x01\x02\x03\x04random binary \xff\xfe payload"),
    '', extractor::NOEXT];

// EDGE: empty file → failed.
$fixtures[] = ['empty.txt', gai_fixture($dir, 'empty.txt', ''), '', extractor::FAILED];

// Run.

$pass = 0;
$fail = 0;
foreach ($fixtures as [$name, $path, $exptype, $expstatus]) {
    $r = extractor::extract($path, $name);
    $typeok = ($exptype === '' || $r['type'] === $exptype);
    $statusok = ($r['status'] === $expstatus);
    $ok = $typeok && $statusok;
    $ok ? $pass++ : $fail++;
    printf(
        "%-14s type=%-5s status=%-12s chars=%-7d expected(%s/%s) %s\n",
        $name,
        $r['type'] ?: '-',
        $r['status'],
        $r['chars'],
        $exptype ?: '*',
        $expstatus,
        $ok ? 'OK' : 'FAIL'
    );
    if (!$ok && $r['error']) {
        echo "    error: " . $r['error'] . "\n";
    }
    if ($r['status'] === extractor::DONE) {
        $snippet = preg_replace('/\s+/', ' ', \core_text::substr($r['text'], 0, 90));
        echo "    text: " . $snippet . "\n";
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
