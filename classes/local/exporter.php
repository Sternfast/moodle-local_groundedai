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
 * Render a result to a downloadable file.
 *
 * One structured intermediate in, a stored file out. Tabular results go through core's dataformat
 * writers (streaming, CSV-injection-safe); a narrative goes through the core PDF wrapper.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_groundedai\local;

/**
 * Export a staff result as Excel, CSV or PDF.
 */
class exporter {
    /** @var string The file area that holds generated exports. */
    const FILEAREA = 'export';

    /** @var string[] Tabular output formats, mapped to the core dataformat name. */
    const TABLE_FORMATS = ['excel' => 'excel', 'csv' => 'csv', 'pdf' => 'pdf'];

    /**
     * Export a table (columns + rows) to Excel, CSV or PDF.
     *
     * @param int $conversationid Conversation the export belongs to.
     * @param int $userid Acting user.
     * @param string $title A human title; becomes the file name.
     * @param string[] $columns Header labels, in order.
     * @param array[] $rows Each row an array of cell values aligned to $columns.
     * @param string $format One of excel|csv|pdf.
     * @return \stored_file
     */
    public static function table(
        int $conversationid,
        int $userid,
        string $title,
        array $columns,
        array $rows,
        string $format
    ): \stored_file {
        $conv = self::prepare($conversationid, $userid);
        $context = \context::instance_by_id($conv->contextid);
        $dataformat = self::TABLE_FORMATS[$format] ?? 'excel';

        $base = self::safe_base($title);
        self::delete_existing($context->id, $conversationid, $base);

        $filerecord = [
            'contextid' => $context->id,
            'component' => 'local_groundedai',
            'filearea'  => self::FILEAREA,
            'itemid'    => $conversationid,
            'filepath'  => '/',
            'filename'  => $base, // The writer appends the extension.
        ];
        // Normalise every row to a clean list of scalar strings aligned to the columns.
        $width = count($columns);
        $iterator = (function () use ($rows, $width) {
            foreach ($rows as $row) {
                $values = array_values((array) $row);
                $values = array_slice(array_pad($values, $width, ''), 0, $width);
                yield array_map(static fn($v) => is_scalar($v) ? (string) $v : '', $values);
            }
        })();

        return \core\dataformat::write_data_to_filearea($filerecord, $dataformat, array_values($columns), $iterator);
    }

    /**
     * Export a narrative (title + HTML body) to PDF.
     *
     * @param int $conversationid Conversation the export belongs to.
     * @param int $userid Acting user.
     * @param string $title Document title and file name.
     * @param string $bodyhtml Body as a restricted HTML subset (assistant-generated → sanitised here).
     * @return \stored_file
     */
    public static function document(int $conversationid, int $userid, string $title, string $bodyhtml): \stored_file {
        global $CFG;
        $conv = self::prepare($conversationid, $userid);
        $context = \context::instance_by_id($conv->contextid);
        require_once($CFG->libdir . '/pdflib.php');

        // Body is untrusted: strip scripts and disallowed markup before TCPDF sees it.
        $html = '<h1>' . s($title) . '</h1>' . clean_text($bodyhtml, FORMAT_HTML);

        $pdf = new \pdf();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();
        $pdf->writeHTML($html, true, false, true, false, '');
        $content = $pdf->Output('', 'S');

        $base = self::safe_base($title);
        self::delete_existing($context->id, $conversationid, $base);
        $filerecord = (object) [
            'contextid' => $context->id,
            'component' => 'local_groundedai',
            'filearea'  => self::FILEAREA,
            'itemid'    => $conversationid,
            'filepath'  => '/',
            'filename'  => $base . '.pdf',
        ];
        return get_file_storage()->create_file_from_string($filerecord, $content);
    }

    /**
     * A force-download URL for a generated export file.
     *
     * @param \stored_file $file
     * @return \moodle_url
     */
    public static function file_url(\stored_file $file): \moodle_url {
        return \moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            $file->get_component(),
            $file->get_filearea(),
            $file->get_itemid(),
            $file->get_filepath(),
            $file->get_filename(),
            true
        );
    }

    /**
     * Load the conversation, assert ownership, and require the export capability in its context.
     *
     * @param int $conversationid Conversation id.
     * @param int $userid Acting user.
     * @return \stdClass
     */
    protected static function prepare(int $conversationid, int $userid): \stdClass {
        global $DB;
        $conv = $DB->get_record('local_groundedai_conversations', ['id' => $conversationid], '*', MUST_EXIST);
        if ((int) $conv->userid !== $userid) {
            throw new \moodle_exception('notyours', 'local_groundedai');
        }
        $context = \context::instance_by_id($conv->contextid);
        require_capability('local/groundedai:export', $context, $userid);
        return $conv;
    }

    /**
     * Remove any earlier export of the same title so re-exporting overwrites cleanly.
     *
     * @param int $contextid Context id.
     * @param int $itemid Conversation id (the area item).
     * @param string $base File base name without extension.
     */
    protected static function delete_existing(int $contextid, int $itemid, string $base): void {
        $fs = get_file_storage();
        $files = $fs->get_area_files(
            $contextid,
            'local_groundedai',
            self::FILEAREA,
            $itemid,
            'filename',
            false
        );
        foreach ($files as $file) {
            if (pathinfo($file->get_filename(), PATHINFO_FILENAME) === $base) {
                $file->delete();
            }
        }
    }

    /**
     * Turn a title into a safe, bounded file base name.
     *
     * @param string $title
     * @return string
     */
    protected static function safe_base(string $title): string {
        $base = clean_param(trim($title), PARAM_FILE);
        $base = trim($base);
        if ($base === '') {
            $base = 'export';
        }
        return \core_text::substr($base, 0, 80);
    }
}
