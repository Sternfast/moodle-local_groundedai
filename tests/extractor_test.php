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
 * Edge-case tests for the file-to-text extractor.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_groundedai;

use local_groundedai\local\extractor;

/**
 * Edge-case tests for the file-to-text extractor.
 *
 * @covers \local_groundedai\local\extractor
 */
final class extractor_test extends \advanced_testcase {
    /** @var string Writable working directory for fixtures. */
    private string $dir;

    /**
     * Set up a per-test working directory.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->dir = make_request_directory();
    }

    /**
     * Write bytes to a fixture file and return its path.
     *
     * @param string $name File name.
     * @param string $bytes Raw bytes.
     * @return string
     */
    private function fixture(string $name, string $bytes): string {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $bytes);
        return $path;
    }

    /**
     * Plain text with a UTF-8 BOM is read and the BOM does not corrupt the text.
     */
    public function test_txt_with_bom(): void {
        $path = $this->fixture('a.txt', "\xEF\xBB\xBFHello world.\nSecond line.");
        $r = extractor::extract($path, 'a.txt');
        $this->assertSame(extractor::DONE, $r['status']);
        $this->assertSame('txt', $r['type']);
        $this->assertStringContainsString('Hello world.', $r['text']);
        $this->assertStringNotContainsString("\xEF\xBB\xBF", $r['text']);
    }

    /**
     * Non-UTF-8 (latin1) text is normalised to UTF-8 rather than nulled out.
     */
    public function test_latin1_is_converted(): void {
        // The string "Café résumé naïve" encoded as ISO-8859-1.
        $path = $this->fixture('latin1.txt', "Caf\xe9 r\xe9sum\xe9 na\xefve");
        $r = extractor::extract($path, 'latin1.txt');
        $this->assertSame(extractor::DONE, $r['status']);
        $this->assertSame('Café résumé naïve', trim($r['text']));
    }

    /**
     * An empty file cannot be read and reports failed, not done.
     */
    public function test_empty_file_fails(): void {
        $path = $this->fixture('empty.txt', '');
        $r = extractor::extract($path, 'empty.txt');
        $this->assertSame(extractor::FAILED, $r['status']);
    }

    /**
     * HTML is reduced to text and script/style content is stripped.
     */
    public function test_html_strips_scripts(): void {
        $html = "<!doctype html><html><head><style>.x{color:red}</style>"
            . "<script>alert('x');var secret=1;</script></head>"
            . "<body><h1>Welcome</h1><p>Read this paragraph.</p></body></html>";
        $path = $this->fixture('page.html', $html);
        $r = extractor::extract($path, 'page.html');
        $this->assertSame(extractor::DONE, $r['status']);
        $this->assertSame('html', $r['type']);
        // The html_to_text() helper renders <h1> in upper case, so match case-insensitively.
        $this->assertStringContainsStringIgnoringCase('Welcome', $r['text']);
        $this->assertStringContainsString('Read this paragraph.', $r['text']);
        $this->assertStringNotContainsString('alert(', $r['text']);
        $this->assertStringNotContainsString('color:red', $r['text']);
    }

    /**
     * A macro-enabled Office type is rejected on safety grounds, not parsed.
     */
    public function test_blocked_extension_rejected(): void {
        $path = $this->fixture('malware.docm', 'anything');
        $r = extractor::extract($path, 'malware.docm');
        $this->assertSame(extractor::REJECTED, $r['status']);
    }

    /**
     * A real XLSX written by the bundled OpenSpout writer is read back to text.
     */
    public function test_xlsx_roundtrip(): void {
        $path = $this->dir . '/b.xlsx';
        $writer = new \OpenSpout\Writer\XLSX\Writer();
        $writer->openToFile($path);
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(['Name', 'Grade']));
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(['Ada', 95]));
        $writer->close();
        $r = extractor::extract($path, 'b.xlsx');
        $this->assertSame(extractor::DONE, $r['status']);
        $this->assertSame('xlsx', $r['type']);
        $this->assertStringContainsString('Ada', $r['text']);
        $this->assertStringContainsString('Grade', $r['text']);
    }

    /**
     * A minimal DOCX (OOXML) has its paragraph text extracted.
     */
    public function test_docx_text(): void {
        $docxxml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            . '<w:p><w:r><w:t>Hello from the docx.</w:t></w:r></w:p>'
            . '</w:body></w:document>';
        $path = $this->make_zip('c.docx', [
            '[Content_Types].xml' => '<?xml version="1.0"?><Types/>',
            'word/document.xml' => $docxxml,
        ]);
        $r = extractor::extract($path, 'c.docx');
        $this->assertSame(extractor::DONE, $r['status']);
        $this->assertSame('docx', $r['type']);
        $this->assertStringContainsString('Hello from the docx.', $r['text']);
    }

    /**
     * A minimal PPTX (OOXML) has its slide text extracted.
     */
    public function test_pptx_slide_text(): void {
        $slide = '<?xml version="1.0"?>'
            . '<sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
            . '<a:t>Slide one talking point.</a:t></sld>';
        $path = $this->make_zip('d.pptx', [
            '[Content_Types].xml' => '<?xml version="1.0"?><Types/>',
            'ppt/presentation.xml' => '<?xml version="1.0"?><p:presentation/>',
            'ppt/slides/slide1.xml' => $slide,
        ]);
        $r = extractor::extract($path, 'd.pptx');
        $this->assertSame(extractor::DONE, $r['status']);
        $this->assertSame('pptx', $r['type']);
        $this->assertStringContainsString('Slide one talking point.', $r['text']);
    }

    /**
     * A ZIP of readable members is walked and their text concatenated.
     */
    public function test_zip_reads_members(): void {
        $path = $this->make_zip('bundle.zip', [
            'notes/one.txt' => 'First member text.',
            'notes/two.txt' => 'Second member text.',
        ]);
        $r = extractor::extract($path, 'bundle.zip');
        $this->assertSame(extractor::DONE, $r['status']);
        $this->assertStringContainsString('First member text.', $r['text']);
        $this->assertStringContainsString('Second member text.', $r['text']);
    }

    /**
     * A file with no recognised type/extension reports no-extractor, not a crash.
     */
    public function test_unknown_binary_is_noext(): void {
        $path = $this->fixture('mystery.bin', "\x89\x7f\x01\x02random\xff\xfe");
        $r = extractor::extract($path, 'mystery.bin');
        $this->assertSame(extractor::NOEXT, $r['status']);
    }

    /**
     * Build a zip of {entry => content} and return its path.
     *
     * @param string $name Zip file name.
     * @param array $entries Map of in-zip path to content.
     * @return string
     */
    private function make_zip(string $name, array $entries): string {
        $path = $this->dir . '/' . $name;
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($entries as $entry => $content) {
            $zip->addFromString($entry, $content);
        }
        $zip->close();
        return $path;
    }
}
