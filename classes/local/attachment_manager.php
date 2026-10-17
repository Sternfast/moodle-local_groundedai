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
 * Attach uploaded files to a conversation and manage their extracted text.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_groundedai\local;

use local_groundedai\task\extract_text;

/**
 * The attachment store: one file area item per file, extraction run in an adhoc task.
 */
class attachment_manager {
    /** @var string The file area that holds conversation attachments. */
    const FILEAREA = 'attachment';
    /** @var int Largest single upload we accept (bytes). */
    const MAX_BYTES = 20971520;   // 20 MB.
    /** @var int Most characters a single tool read returns. */
    const READ_MAX = 50000;

    /**
     * File extensions the picker and the save step accept. Content is re-verified during extraction,
     * so this is the first of several gates, not the only one. Macro/binary types are never listed.
     *
     * @return string[]
     */
    public static function accepted_types(): array {
        return ['.txt', '.text', '.csv', '.md', '.log', '.xlsx', '.docx', '.pptx', '.pdf', '.zip'];
    }

    /**
     * Move one just-uploaded draft file into the conversation's permanent area and queue extraction.
     *
     * @param int $conversationid Conversation id.
     * @param int $userid Acting (owning) user.
     * @param int $draftitemid The draft area the picker uploaded into.
     * @return int The new attachment id.
     */
    public static function add_file(int $conversationid, int $userid, int $draftitemid): int {
        global $DB;
        $conv = self::owned_conversation($conversationid, $userid);
        $context = \context::instance_by_id($conv->contextid);
        $now = time();

        // The row id is the per-file area itemid, so it must be inserted before the file is moved.
        // The whole thing runs in one transaction: if the save fails, the placeholder row rolls
        // back rather than being orphaned under the UNIQUE(fileid) index (which, left behind, would
        // collide with every later upload). The transaction also serialises concurrent uploads.
        $transaction = $DB->start_delegated_transaction();
        try {
            $attachid = (int) $DB->insert_record('local_groundedai_attach', (object) [
                'conversationid' => $conversationid,
                'userid'         => $userid,
                'contextid'      => $context->id,
                'fileid'         => 0,
                'contenthash'    => '',
                'filename'       => '',
                'mimetype'       => '',
                'type'           => '',
                'status'         => 'pending',
                'chars'          => 0,
                'content'        => null,
                'error'          => null,
                'timecreated'    => $now,
                'timemodified'   => $now,
            ]);

            $options = [
                'subdirs'        => 0,
                'maxfiles'       => 1,
                'maxbytes'       => self::MAX_BYTES,
                'accepted_types' => self::accepted_types(),
            ];
            // The draft upload already ran quota, accepted_types and antivirus; this move is
            // itemid-scoped, so it can never touch another attachment's file.
            file_save_draft_area_files(
                $draftitemid,
                $context->id,
                'local_groundedai',
                self::FILEAREA,
                $attachid,
                $options
            );

            $fs = get_file_storage();
            $files = $fs->get_area_files(
                $context->id,
                'local_groundedai',
                self::FILEAREA,
                $attachid,
                'itemid, filepath, filename',
                false
            );
            $file = reset($files);
            if (!$file) {
                throw new \moodle_exception('uploadnofile', 'local_groundedai');
            }

            $DB->update_record('local_groundedai_attach', (object) [
                'id'           => $attachid,
                'fileid'       => (int) $file->get_id(),
                'contenthash'  => $file->get_contenthash(),
                'filename'     => $file->get_filename(),
                'mimetype'     => (string) $file->get_mimetype(),
                'timemodified' => time(),
            ]);

            \core\task\manager::queue_adhoc_task(extract_text::instance($attachid), true);
            $transaction->allow_commit();
            return $attachid;
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            throw $e;   // The rollback() call rethrows; this is unreachable but keeps the contract explicit.
        }
    }

    /**
     * Allocate a fresh, empty draft area for one upload.
     *
     * A new itemid per upload is what keeps file_save_draft_area_files (maxfiles=1) from re-copying
     * an earlier file when several files are attached in one page session.
     *
     * @param int $conversationid Conversation id.
     * @param int $userid Acting (owning) user.
     * @return int A new unused draft item id.
     */
    public static function prepare_upload(int $conversationid, int $userid): int {
        self::owned_conversation($conversationid, $userid);
        return file_get_unused_draft_itemid();
    }

    /**
     * The extraction worker (run from the adhoc task). Reuses a prior identical extraction.
     *
     * @param int $attachid Attachment id.
     */
    public static function process(int $attachid): void {
        global $DB;
        $row = $DB->get_record('local_groundedai_attach', ['id' => $attachid]);
        if (!$row) {
            return;
        }
        $fs = get_file_storage();
        $file = $fs->get_file_by_id((int) $row->fileid);
        if (!$file) {
            self::store($attachid, extractor::FAILED, '', '', 0, 'The uploaded file could not be found.');
            return;
        }

        // Identical content already extracted elsewhere? Reuse it rather than re-reading.
        $contentset = $DB->sql_isnotempty('local_groundedai_attach', 'content', true, true);
        $prior = $DB->get_record_select(
            'local_groundedai_attach',
            'contenthash = :h AND status = :s AND id <> :id AND ' . $contentset,
            ['h' => $row->contenthash, 's' => extractor::DONE, 'id' => $attachid],
            '*',
            IGNORE_MULTIPLE
        );
        if ($prior) {
            self::store($attachid, $prior->status, $prior->type, (string) $prior->content, (int) $prior->chars, null);
            return;
        }

        $dir = make_request_directory();
        $tmp = $dir . '/' . $file->get_id() . '_' . clean_param($file->get_filename(), PARAM_FILE);
        $file->copy_content_to($tmp);
        $res = extractor::extract($tmp, $file->get_filename());
        self::store($attachid, $res['status'], $res['type'], $res['text'], $res['chars'], $res['error']);
    }

    /**
     * Mark an attachment failed with a message (used by the task's catch-all).
     *
     * @param int $attachid Attachment id.
     * @param string $message Short reason.
     */
    public static function fail(int $attachid, string $message): void {
        self::store($attachid, extractor::FAILED, '', '', 0, $message);
    }

    /**
     * Persist an extraction result.
     *
     * @param int $attachid Attachment id.
     * @param string $status One of the extractor statuses.
     * @param string $type Detected type.
     * @param string $content Extracted text.
     * @param int $chars Character count.
     * @param string|null $error Short error/reason.
     */
    protected static function store(
        int $attachid,
        string $status,
        string $type,
        string $content,
        int $chars,
        ?string $error
    ): void {
        global $DB;
        $DB->update_record('local_groundedai_attach', (object) [
            'id'           => $attachid,
            'status'       => $status,
            'type'         => $type,
            'content'      => ($content === '') ? null : $content,
            'chars'        => $chars,
            'error'        => $error ? \core_text::substr($error, 0, 255) : null,
            'timemodified' => time(),
        ]);
    }

    /**
     * The attachments on a conversation (metadata only — never the text), for the UI and tools.
     *
     * @param int $conversationid Conversation id.
     * @param int $userid Acting (owning) user.
     * @return array List of {id, filename, type, status, chars, error, timecreated}.
     */
    public static function list_for(int $conversationid, int $userid): array {
        global $DB;
        self::owned_conversation($conversationid, $userid);
        $rows = $DB->get_records(
            'local_groundedai_attach',
            ['conversationid' => $conversationid],
            'timecreated ASC',
            'id, filename, type, status, chars, error, timecreated'
        );
        return array_values($rows);
    }

    /**
     * Read a chunk of one attachment's extracted text (the staff tool's read path).
     *
     * @param int $attachid Attachment id.
     * @param int $userid Acting (owning) user.
     * @param int $offset Character offset to start from.
     * @param int $length Characters to return (capped at READ_MAX).
     * @param int|null $conversationid If set, the attachment must belong to this conversation.
     * @return array {status, filename, text, total, offset, hasmore}
     */
    public static function read_chunk(
        int $attachid,
        int $userid,
        int $offset = 0,
        int $length = self::READ_MAX,
        ?int $conversationid = null
    ): array {
        global $DB;
        $row = self::owned_attachment($attachid, $userid);
        if ($conversationid !== null && (int) $row->conversationid !== $conversationid) {
            throw new \moodle_exception('notyours', 'local_groundedai');
        }
        $base = [
            'status'   => $row->status,
            'filename' => $row->filename,
            'text'     => '',
            'total'    => (int) $row->chars,
            'offset'   => max(0, $offset),
            'hasmore'  => false,
        ];
        if ($row->status !== extractor::DONE || $row->content === null) {
            return $base;
        }
        $offset = max(0, $offset);
        $length = max(1, min($length, self::READ_MAX));
        $text = \core_text::substr((string) $row->content, $offset, $length);
        $base['text'] = $text;
        $base['hasmore'] = ($offset + \core_text::strlen($text)) < (int) $row->chars;
        return $base;
    }

    /**
     * Delete an attachment and its stored file.
     *
     * @param int $attachid Attachment id.
     * @param int $userid Acting (owning) user.
     */
    public static function delete(int $attachid, int $userid): void {
        global $DB;
        $row = self::owned_attachment($attachid, $userid);
        $file = get_file_storage()->get_file_by_id((int) $row->fileid);
        if ($file) {
            $file->delete();
        }
        $DB->delete_records('local_groundedai_attach', ['id' => $attachid]);
    }

    /**
     * Load a conversation and assert the user owns it.
     *
     * @param int $conversationid Conversation id.
     * @param int $userid Acting user.
     * @return \stdClass
     */
    protected static function owned_conversation(int $conversationid, int $userid): \stdClass {
        global $DB;
        $conv = $DB->get_record('local_groundedai_conversations', ['id' => $conversationid], '*', MUST_EXIST);
        if ((int) $conv->userid !== $userid) {
            throw new \moodle_exception('notyours', 'local_groundedai');
        }
        return $conv;
    }

    /**
     * Load an attachment and assert the user owns its conversation.
     *
     * @param int $attachid Attachment id.
     * @param int $userid Acting user.
     * @return \stdClass
     */
    protected static function owned_attachment(int $attachid, int $userid): \stdClass {
        global $DB;
        $row = $DB->get_record('local_groundedai_attach', ['id' => $attachid], '*', MUST_EXIST);
        self::owned_conversation((int) $row->conversationid, $userid);
        return $row;
    }
}
