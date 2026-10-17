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
 * Convert an uploaded file to plain text.
 *
 * One thin adapter per format; every path returns a single UTF-8 string plus a status.
 * The caller treats the output as untrusted data, never as instructions.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_groundedai\local;

/**
 * Extract text from the supported document formats.
 */
class extractor {
    // Result statuses.
    /** @var string Extraction succeeded. */
    const DONE = 'done';
    /** @var string Extraction failed (corrupt / unreadable). */
    const FAILED = 'failed';
    /** @var string Recognised file but no supported text to extract. */
    const NOEXT = 'noext';
    /** @var string A PDF with no text layer (scanned image). */
    const NOTEXTLAYER = 'notextlayer';
    /** @var string Blocked for safety (macro-enabled, zip bomb, path traversal, oversize). */
    const REJECTED = 'rejected';

    // Safety caps. Policy values — calibrate against real course documents before shipping.
    /** @var int Largest plain-text file we read into memory. */
    const TXT_MAX_BYTES = 10485760;        // 10 MB.
    /** @var int Hardest cap on produced text, so one file cannot exhaust the context or the row. */
    const MAX_CHARS = 2000000;
    /** @var int Largest single member we read out of a container. */
    const ZIP_MAX_ENTRY_BYTES = 52428800;  // 50 MB.
    /** @var int Largest total uncompressed size across a container. */
    const ZIP_MAX_TOTAL_BYTES = 209715200; // 200 MB.
    /** @var int Most members we will look at in a container. */
    const ZIP_MAX_ENTRIES = 1000;
    /** @var int Reject when a member's uncompressed:compressed ratio exceeds this (zip bomb). */
    const ZIP_MAX_RATIO = 100;
    /** @var int How deep we recurse into zips-within-zips. */
    const ZIP_MAX_DEPTH = 2;

    /** @var string[] Extensions we refuse outright (macro-enabled or legacy binary Office). */
    const BLOCKED_EXTENSIONS = [
        'docm', 'xlsm', 'pptm', 'dotm', 'xltm', 'potm', 'xlsb',
        'doc', 'xls', 'ppt', 'exe', 'bat', 'cmd', 'sh', 'js', 'vbs',
    ];

    /**
     * Extract text from a file already written to the local filesystem.
     *
     * @param string $path Real filesystem path to the file (never a stream wrapper).
     * @param string $filename The claimed file name (used for the extension safety check).
     * @return array{type:string,status:string,text:string,chars:int,error:?string}
     */
    public static function extract(string $path, string $filename): array {
        $ext = \core_text::strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (in_array($ext, self::BLOCKED_EXTENSIONS, true)) {
            return self::result('', self::REJECTED, '', 'Macro-enabled or executable file types are not accepted.');
        }
        if (!is_readable($path) || filesize($path) === 0) {
            return self::result('', self::FAILED, '', 'The file could not be read.');
        }

        try {
            $type = self::detect_type($path);
            switch ($type) {
                case 'txt':
                    return self::finish('txt', self::extract_txt($path));
                case 'html':
                    return self::finish('html', self::extract_html($path));
                case 'xlsx':
                    return self::finish('xlsx', self::extract_xlsx($path));
                case 'docx':
                    return self::finish('docx', self::extract_ooxml($path, 'docx'));
                case 'pptx':
                    return self::finish('pptx', self::extract_ooxml($path, 'pptx'));
                case 'zip':
                    return self::finish('zip', self::extract_zip($path, 1));
                case 'pdf':
                    [$status, $text] = self::extract_pdf($path);
                    $out = self::finish('pdf', $text);
                    // Keep the PDF verdict (notextlayer/failed), except do not claim DONE when the
                    // text came back empty after cleaning.
                    if (!($status === self::DONE && $out['chars'] === 0)) {
                        $out['status'] = $status;
                    }
                    return $out;
                default:
                    return self::result('', self::NOEXT, '', 'This file type is not supported.');
            }
        } catch (rejected_exception $e) {
            return self::result('', self::REJECTED, '', $e->getMessage());
        } catch (\Throwable $e) {
            return self::result('', self::FAILED, '', 'Could not read the file: ' . $e->getMessage());
        }
    }

    /**
     * Decide the real format from content, not the extension.
     *
     * OOXML files and plain zips all sniff as application/zip, so we open the archive and require
     * the format's marker member. PDFs are identified by their %PDF header.
     *
     * @param string $path
     * @return string One of txt|xlsx|docx|pptx|zip|pdf|'' (unknown).
     */
    protected static function detect_type(string $path): string {
        $mime = '';
        if (class_exists('\\finfo')) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = (string) $finfo->file($path);
        }

        $header = (string) file_get_contents($path, false, null, 0, 8);
        if (strncmp($header, '%PDF-', 5) === 0 || $mime === 'application/pdf') {
            return 'pdf';
        }

        // Zip container? (PK magic, or a zip-family mime.)
        $iszip = (strncmp($header, "PK\x03\x04", 4) === 0)
            || (strncmp($header, "PK\x05\x06", 4) === 0)
            || in_array($mime, ['application/zip', 'application/x-zip-compressed'], true);
        if ($iszip) {
            return self::detect_zip_subtype($path);
        }

        // HTML (e.g. SCORM pages, embedded web content): read the visible text, not the tag soup.
        if ($mime === 'text/html' || $mime === 'application/xhtml+xml') {
            return 'html';
        }
        $head = \core_text::strtolower(substr((string) file_get_contents($path, false, null, 0, 512), 0, 512));
        if (strpos($head, '<!doctype html') !== false || strpos($head, '<html') !== false) {
            return 'html';
        }

        // Treat anything textual as txt.
        if (
            $mime === '' || strncmp($mime, 'text/', 5) === 0
                || in_array($mime, ['application/json', 'application/xml', 'inode/x-empty'], true)
        ) {
            return 'txt';
        }
        return '';
    }

    /**
     * Extract the visible text from an HTML document (strips scripts, styles and markup).
     *
     * @param string $path
     * @return string
     */
    protected static function extract_html(string $path): string {
        if (filesize($path) > self::TXT_MAX_BYTES) {
            throw new rejected_exception('The HTML file is larger than the allowed limit.');
        }
        $raw = (string) file_get_contents($path);
        if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
            $raw = substr($raw, 3);
        }
        return trim(html_to_text($raw, 0, false));
    }

    /**
     * Distinguish an OOXML document from a plain zip by its marker member.
     *
     * @param string $path
     * @return string docx|xlsx|pptx|zip
     */
    protected static function detect_zip_subtype(string $path): string {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return 'zip';
        }
        // Macro projects are a hard reject, OOXML or not.
        if (
            $zip->locateName('word/vbaProject.bin') !== false
                || $zip->locateName('xl/vbaProject.bin') !== false
                || $zip->locateName('ppt/vbaProject.bin') !== false
        ) {
            $zip->close();
            throw new rejected_exception('Macro-enabled documents are not accepted.');
        }
        $subtype = 'zip';
        if ($zip->locateName('word/document.xml') !== false) {
            $subtype = 'docx';
        } else if ($zip->locateName('xl/workbook.xml') !== false) {
            $subtype = 'xlsx';
        } else if ($zip->locateName('ppt/presentation.xml') !== false) {
            $subtype = 'pptx';
        }
        $zip->close();
        return $subtype;
    }

    /**
     * Plain-text extraction: cap the size, strip the BOM, normalise to UTF-8.
     *
     * @param string $path
     * @return string
     */
    protected static function extract_txt(string $path): string {
        if (filesize($path) > self::TXT_MAX_BYTES) {
            throw new rejected_exception('The text file is larger than the allowed limit.');
        }
        $raw = (string) file_get_contents($path);
        // Resolve the encoding from the byte-order mark where present, else sniff, and
        // normalise everything to UTF-8 through core_text (Moodle's iconv/mbstring wrapper).
        if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
            return \core_text::convert(substr($raw, 3), 'utf-8', 'utf-8');
        }
        if (strncmp($raw, "\xFF\xFE", 2) === 0) {
            return \core_text::convert(substr($raw, 2), 'utf-16le', 'utf-8');
        }
        if (strncmp($raw, "\xFE\xFF", 2) === 0) {
            return \core_text::convert(substr($raw, 2), 'utf-16be', 'utf-8');
        }
        if (function_exists('mb_check_encoding') && mb_check_encoding($raw, 'UTF-8')) {
            return $raw;
        }
        $enc = mb_detect_encoding($raw, ['ISO-8859-1', 'Windows-1252'], true) ?: 'Windows-1252';
        return \core_text::convert($raw, $enc, 'utf-8');
    }

    /**
     * Spreadsheet extraction via the bundled OpenSpout reader (streams rows, constant memory).
     *
     * @param string $path
     * @return string
     */
    protected static function extract_xlsx(string $path): string {
        $reader = new \OpenSpout\Reader\XLSX\Reader();
        $reader->open($path);
        $out = '';
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                if (method_exists($sheet, 'isVisible') && !$sheet->isVisible()) {
                    continue;
                }
                $out .= '# ' . $sheet->getName() . "\n";
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = [];
                    foreach ($row->toArray() as $value) {
                        if ($value instanceof \DateTimeInterface) {
                            $value = $value->format('Y-m-d');
                        }
                        $cells[] = (string) $value;
                    }
                    $line = rtrim(implode("\t", $cells));
                    if ($line !== '') {
                        $out .= $line . "\n";
                        if (strlen($out) > self::MAX_CHARS) {
                            break 2;
                        }
                    }
                }
                $out .= "\n";
            }
        } finally {
            $reader->close();
        }
        return $out;
    }

    /**
     * Word / PowerPoint extraction: walk the OOXML parts with XMLReader.
     *
     * @param string $path
     * @param string $kind docx|pptx
     * @return string
     */
    protected static function extract_ooxml(string $path, string $kind): string {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('archive could not be opened');
        }
        try {
            if ($kind === 'docx') {
                $parts = ['word/document.xml'];
                foreach (self::zip_members_matching($zip, '~^word/(header|footer)\d*\.xml$~') as $name) {
                    $parts[] = $name;
                }
                $out = '';
                foreach ($parts as $name) {
                    $out .= self::ooxml_part_text($zip, $name, 'w');
                }
                return $out;
            }

            // Slides in filename order, then speaker notes (pptx).
            $slides = [];
            foreach (self::zip_members_matching($zip, '~^ppt/slides/slide(\d+)\.xml$~', $m) as $i => $name) {
                $slides[(int) $m[$i][1]] = $name;
            }
            ksort($slides);
            $out = '';
            $n = 0;
            foreach ($slides as $name) {
                $n++;
                $text = self::ooxml_part_text($zip, $name, 'a');
                if (trim($text) !== '') {
                    $out .= 'Slide ' . $n . ":\n" . $text . "\n\n";
                }
                if (strlen($out) > self::MAX_CHARS) {
                    break;
                }
            }
            return $out;
        } finally {
            $zip->close();
        }
    }

    /**
     * Pull readable text from one OOXML part.
     *
     * @param \ZipArchive $zip
     * @param string $name Member name.
     * @param string $ns Namespace prefix the text tags use ('w' for Word, 'a' for Drawing).
     * @return string
     */
    protected static function ooxml_part_text(\ZipArchive $zip, string $name, string $ns): string {
        // Enforce the same caps as the plain-zip walk BEFORE reading the member into memory:
        // getFromName() allocates the whole uncompressed part, so a bomb-compressed part could
        // otherwise exhaust memory with an uncatchable fatal error.
        $stat = $zip->statName($name);
        if ($stat === false) {
            return '';
        }
        if ((int) $stat['size'] > self::ZIP_MAX_ENTRY_BYTES) {
            throw new rejected_exception('A part of the document is too large to read.');
        }
        if (!empty($stat['comp_size']) && ((int) $stat['size'] / (int) $stat['comp_size']) > self::ZIP_MAX_RATIO) {
            throw new rejected_exception('The document looks like a decompression bomb.');
        }
        $xml = $zip->getFromName($name);
        if ($xml === false || $xml === '') {
            return '';
        }
        // Defuse XXE / entity-expansion before the parser sees a DOCTYPE.
        if (preg_match('/<!DOCTYPE/i', $xml)) {
            throw new rejected_exception('The document contains a disallowed document-type declaration.');
        }
        $reader = new \XMLReader();
        if (!$reader->XML($xml, 'UTF-8', LIBXML_NONET)) {
            return '';
        }
        $out = '';
        try {
            while ($reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT) {
                    continue;
                }
                // The local name drops the namespace prefix (w:/a:).
                switch ($reader->localName) {
                    case 't':
                        $out .= $reader->readString();
                        break;
                    case 'p':
                        $out .= "\n";
                        break;
                    case 'br':
                    case 'cr':
                        $out .= "\n";
                        break;
                    case 'tab':
                        $out .= "\t";
                        break;
                }
            }
        } finally {
            $reader->close();
        }
        return $out;
    }

    /**
     * Walk a plain zip by index, honouring the safety caps, and concatenate the text of each
     * supported member. Never extracts to disk.
     *
     * @param string $path
     * @param int $depth Current nesting depth (1 = top-level upload).
     * @param array|null $budget Running {total, entries} shared across the whole archive tree.
     * @return string
     */
    protected static function extract_zip(string $path, int $depth, ?array &$budget = null): string {
        if ($budget === null) {
            // The caps bound the whole tree, not each nested archive, so the counters are shared.
            $budget = ['total' => 0, 'entries' => 0];
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('archive could not be opened');
        }
        $out = '';
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    continue;
                }
                $name = $stat['name'];
                if ($name === '' || substr($name, -1) === '/') {
                    continue;   // Directory entry.
                }
                if (self::is_unsafe_path($name)) {
                    throw new rejected_exception('The archive contains an unsafe path.');
                }
                if (++$budget['entries'] > self::ZIP_MAX_ENTRIES) {
                    throw new rejected_exception('The archive contains too many files.');
                }
                $size = (int) $stat['size'];
                $compsize = (int) $stat['comp_size'];
                if ($size > self::ZIP_MAX_ENTRY_BYTES) {
                    throw new rejected_exception('A file inside the archive is too large.');
                }
                if ($compsize > 0 && ($size / $compsize) > self::ZIP_MAX_RATIO) {
                    throw new rejected_exception('The archive looks like a decompression bomb.');
                }
                $budget['total'] += $size;
                if ($budget['total'] > self::ZIP_MAX_TOTAL_BYTES) {
                    throw new rejected_exception('The archive is too large once uncompressed.');
                }

                $member = self::extract_zip_member($zip, $i, $name, $depth, $budget);
                if ($member !== '') {
                    $out .= '=== ' . $name . " ===\n" . $member . "\n\n";
                    if (strlen($out) > self::MAX_CHARS) {
                        break;
                    }
                }
            }
        } finally {
            $zip->close();
        }
        return $out;
    }

    /**
     * Whether a zip member name is an absolute path, a drive path, or contains a ".." segment.
     * Matches a real traversal segment, not any occurrence of the two-character substring.
     *
     * @param string $name
     * @return bool
     */
    protected static function is_unsafe_path(string $name): bool {
        if ($name[0] === '/' || $name[0] === '\\' || preg_match('~^[a-zA-Z]:~', $name)) {
            return true;
        }
        return in_array('..', explode('/', str_replace('\\', '/', $name)), true);
    }

    /**
     * Read one zip member to a temp file and extract it by its own type (bounded recursion).
     *
     * @param \ZipArchive $zip
     * @param int $index
     * @param string $name
     * @param int $depth
     * @param array|null $budget Running {total, entries} shared across the archive tree.
     * @return string
     */
    protected static function extract_zip_member(
        \ZipArchive $zip,
        int $index,
        string $name,
        int $depth,
        ?array &$budget = null
    ): string {
        $ext = \core_text::strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($ext, self::BLOCKED_EXTENSIONS, true)) {
            return '';
        }
        $stream = $zip->getStream($name);
        if ($stream === false) {
            return '';
        }
        $dir = make_request_directory();
        $tmp = $dir . '/' . md5($name) . ($ext !== '' ? '.' . $ext : '');
        $dest = fopen($tmp, 'wb');
        if ($dest === false) {
            fclose($stream);   // Valid resource here (checked above); skip this unreadable member cleanly.
            return '';
        }
        $written = 0;
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 131072);
                if ($chunk === false) {
                    break;
                }
                $written += strlen($chunk);
                if ($written > self::ZIP_MAX_ENTRY_BYTES) {
                    throw new rejected_exception('A file inside the archive is too large.');
                }
                fwrite($dest, $chunk);
            }
        } finally {
            fclose($stream);
            fclose($dest);
        }

        try {
            // A nested plain zip only recurses while we have depth budget.
            $type = self::detect_type($tmp);
            switch ($type) {
                case 'txt':
                    return self::extract_txt($tmp);
                case 'html':
                    return self::extract_html($tmp);
                case 'xlsx':
                    return self::extract_xlsx($tmp);
                case 'docx':
                    return self::extract_ooxml($tmp, 'docx');
                case 'pptx':
                    return self::extract_ooxml($tmp, 'pptx');
                case 'zip':
                    return $depth < self::ZIP_MAX_DEPTH ? self::extract_zip($tmp, $depth + 1, $budget) : '';
                default:
                    return '';
            }
        } finally {
            // Free the member's temp file immediately rather than letting them accumulate across a walk.
            @unlink($tmp);
        }
    }

    /**
     * PDF extraction. Prefers the pdftotext binary (best layout) when an admin has configured it,
     * and otherwise falls back to the bundled pure-PHP parser so PDFs read on any host.
     *
     * @param string $path
     * @return array{0:string,1:string} [status, text]
     */
    protected static function extract_pdf(string $path): array {
        $bin = trim((string) get_config('local_groundedai', 'pdftotextpath'));
        if ($bin !== '' && is_executable($bin)) {
            [$status, $text] = self::extract_pdf_poppler($bin, $path);
            // Only fall through to the PHP parser if poppler could not run at all.
            if ($status !== self::FAILED) {
                return [$status, $text];
            }
        }
        return self::extract_pdf_php($path);
    }

    /**
     * PDF text via the poppler pdftotext binary.
     *
     * @param string $bin Path to pdftotext.
     * @param string $path Path to the PDF.
     * @return array{0:string,1:string} [status, text]
     */
    protected static function extract_pdf_poppler(string $bin, string $path): array {
        $dir = make_request_directory();
        $out = $dir . '/out.txt';
        $cmd = escapeshellarg($bin) . ' -q -enc UTF-8 -eol unix '
            . escapeshellarg($path) . ' ' . escapeshellarg($out);

        $rc = self::run_bounded($cmd, 60);
        if ($rc !== 0 || !is_readable($out)) {
            return [self::FAILED, ''];
        }
        $text = (string) file_get_contents($out);
        if (trim($text) === '') {
            // Return code 0 with empty output is poppler's signal for a scanned / image-only PDF.
            return [self::NOTEXTLAYER, ''];
        }
        return [self::DONE, $text];
    }

    /**
     * PDF text via the bundled pure-PHP parser (no system binary needed).
     *
     * @param string $path Path to the PDF.
     * @return array{0:string,1:string} [status, text]
     */
    protected static function extract_pdf_php(string $path): array {
        self::register_pdfparser();
        try {
            $config = new \Smalot\PdfParser\Config();
            $config->setRetainImageContent(false);     // We only want text; skip image buffers.
            $parser = new \Smalot\PdfParser\Parser([], $config);
            $pdf = $parser->parseFile($path);
            $text = $pdf->getText();
        } catch (\Throwable $e) {
            // Encrypted/secured PDFs and malformed files land here.
            if (stripos($e->getMessage(), 'secured') !== false || stripos($e->getMessage(), 'encrypt') !== false) {
                return [self::FAILED, ''];
            }
            return [self::NOTEXTLAYER, ''];
        }
        if (trim($text) === '') {
            return [self::NOTEXTLAYER, ''];
        }
        return [self::DONE, $text];
    }

    /**
     * Register a PSR-0 autoloader for the bundled PdfParser library (once).
     */
    protected static function register_pdfparser(): void {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;
        $base = __DIR__ . '/../../thirdparty/pdfparser/src/';
        spl_autoload_register(static function ($class) use ($base) {
            if (strncmp($class, 'Smalot\\PdfParser\\', 17) !== 0) {
                return;
            }
            $file = $base . str_replace('\\', '/', $class) . '.php';
            if (is_file($file)) {
                require_once($file);
            }
        });
    }

    /**
     * Run an external command with a hard wall-clock timeout (exec() has none).
     *
     * @param string $cmd Fully escaped command line.
     * @param int $timeout Seconds before the child is killed.
     * @return int Exit code, or 124 on timeout.
     */
    protected static function run_bounded(string $cmd, int $timeout): int {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            return 127;
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $deadline = time() + $timeout;
        do {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                break;
            }
            if (time() >= $deadline) {
                proc_terminate($proc, 9);
                foreach ($pipes as $p) {
                    fclose($p);
                }
                proc_close($proc);
                return 124;
            }
            usleep(100000);
        } while (true);
        foreach ($pipes as $p) {
            fclose($p);
        }
        $code = $status['exitcode'];
        proc_close($proc);
        return is_int($code) ? $code : 0;
    }

    /**
     * Return the member names of a zip whose name matches a pattern.
     *
     * @param \ZipArchive $zip
     * @param string $pattern
     * @param array|null $matches Filled with the per-name preg_match captures, keyed as the return.
     * @return string[] index => name
     */
    protected static function zip_members_matching(\ZipArchive $zip, string $pattern, &$matches = null): array {
        $names = [];
        $matches = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name !== false && preg_match($pattern, $name, $m)) {
                $names[$i] = $name;
                $matches[$i] = $m;
            }
        }
        return $names;
    }

    /**
     * Normalise extracted text and build the success result.
     *
     * @param string $type
     * @param string $text
     * @return array{type:string,status:string,text:string,chars:int,error:?string}
     */
    protected static function finish(string $type, string $text): array {
        // Normalise to valid UTF-8 first: the bundled PDF parser can return non-UTF-8 bytes, and
        // the control-character strip below (if it validated UTF-8) would otherwise discard the
        // whole string on a single bad byte.
        if ($text !== '' && !mb_check_encoding($text, 'UTF-8')) {
            $enc = mb_detect_encoding($text, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true) ?: 'Windows-1252';
            $text = \core_text::convert($text, $enc, 'utf-8');
        }
        // Drop control characters except tab and newline. The class is ASCII-only and the pattern
        // has no /u modifier, so a stray byte cannot make preg_replace return null and void the text.
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        if (\core_text::strlen($text) > self::MAX_CHARS) {
            $text = \core_text::substr($text, 0, self::MAX_CHARS) . "\n\n[truncated]";
        }
        $text = trim($text);
        $status = ($text === '') ? self::NOEXT : self::DONE;
        return self::result($type, $status, $text, null);
    }

    /**
     * Shape a result array.
     *
     * @param string $type
     * @param string $status
     * @param string $text
     * @param string|null $error
     * @return array{type:string,status:string,text:string,chars:int,error:?string}
     */
    protected static function result(string $type, string $status, string $text, ?string $error): array {
        return [
            'type'   => $type,
            'status' => $status,
            'text'   => $text,
            'chars'  => \core_text::strlen($text),
            'error'  => $error,
        ];
    }
}
