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
 * Staff read-only tool allowlist: schemas (gated by the data-domain allowlist) and dispatch.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_groundedai\local\staff;

use local_groundedai\local\access_manager;

/**
 * The closed set of read verbs the staff model may call. A domain the admin has not enabled is never
 * registered, so the model cannot call it. Every handler re-checks the caller's capability in the
 * item's own context before fetching, and returns only what that user may already see. No writes.
 */
class tools {
    /** @var int Default characters returned by read_material in one call. */
    private const MATERIAL_READ_DEFAULT = 10000;

    /** @var int Hard per-call character cap for read_material. */
    private const MATERIAL_READ_MAX = 20000;

    /** @var array Per-request course-record cache (keyed by course id). */
    private static array $coursecache = [];

    /** @var array Per-request course grade-item-set cache (keyed by course id). */
    private static array $gradeitemcache = [];

    /** @var array Per-request group-visibility cache (keyed by "courseid:userid"). */
    private static array $grouprestrictcache = [];

    /**
     * Clear the per-request caches. Statics live for the whole PHP request in production (which is what
     * we want), but persist across methods in a single PHPUnit process, so tests reset them in setUp.
     */
    public static function reset_caches(): void {
        self::$coursecache = [];
        self::$gradeitemcache = [];
        self::$grouprestrictcache = [];
    }

    /**
     * The data domains this user may reach — the union of their access grants' domains (admins: all).
     *
     * @param int $userid The acting user.
     * @return string[]
     */
    public static function enabled_domains(int $userid): array {
        return access_manager::enabled_domains($userid);
    }

    /**
     * OpenAI-style tool schemas for the user's enabled domains only.
     *
     * @param int $conversationid The conversation, so attachment tools can be offered when it has files.
     * @param int $userid The acting user (scopes the domains and sensitive features offered).
     * @return array
     */
    public static function schemas(int $conversationid = 0, int $userid = 0): array {
        $domains = self::enabled_domains($userid);
        $tools = [];

        $findtypes = [];
        if (in_array('courses', $domains, true)) {
            $findtypes[] = 'courses';
        }
        if (in_array('participants', $domains, true)) {
            $findtypes[] = 'people';
        }
        if ($findtypes) {
            $tools[] = self::fn('find', 'Find courses or people the current user may view.', [
                'type'     => ['type' => 'string', 'enum' => $findtypes, 'description' => 'What to look for.'],
                'courseid' => ['type' => 'integer', 'description' => 'Required when type=people: the course id.'],
                'query'    => ['type' => 'string', 'description' => 'Optional name filter.'],
            ], ['type']);
        }

        if (in_array('courses', $domains, true)) {
            $tools[] = self::fn(
                'get_course_contents',
                'List a course\'s sections (topics) and the activities/resources in each, with types. '
                . 'Use this to see what the course covers before reading any material.',
                ['courseid' => ['type' => 'integer', 'description' => 'The course id.']],
                ['courseid']
            );
            $tools[] = self::fn(
                'read_material',
                'Read the text of one activity or resource: the body of a Page, Book, Label, URL, Lesson, '
                . 'Glossary or Wiki, AND the extracted text of uploaded PDF, Word, PowerPoint or Excel files '
                . 'in a File, Folder or SCORM resource, plus any activity description. Call get_course_contents '
                . 'first for the cmid.',
                [
                    'courseid' => ['type' => 'integer', 'description' => 'The course id.'],
                    'cmid'     => ['type' => 'integer', 'description' => 'The course-module id from get_course_contents.'],
                    'offset'   => ['type' => 'integer', 'description' => 'Character offset to start from (default 0).'],
                    'length'   => ['type' => 'integer', 'description' => 'Characters to read (default 10000, max 20000). '
                        . 'Use "offset" with "total_chars"/"hasmore" from a previous call to read a long document in chunks.'],
                ],
                ['courseid', 'cmid']
            );
            $tools[] = self::fn(
                'get_course_info',
                'Course settings and dates: when the course was created and last edited, its start and end '
                . 'dates, visibility, format and summary.',
                ['courseid' => ['type' => 'integer', 'description' => 'The course id.']],
                ['courseid']
            );
            $tools[] = self::fn(
                'get_announcements',
                'The latest announcements (news) in a course — the title, date and text of each recent post.',
                ['courseid' => ['type' => 'integer', 'description' => 'The course id.']],
                ['courseid']
            );
        }

        if (in_array('grades', $domains, true)) {
            $tools[] = self::fn(
                'get_grades',
                'Get a learner\'s grades in a course. Figures are authoritative from the gradebook.',
                [
                    'courseid' => ['type' => 'integer', 'description' => 'The course id.'],
                    'userid'   => ['type' => 'integer', 'description' => 'Learner id; omit for the current user.'],
                ],
                ['courseid']
            );
            $tools[] = self::fn(
                'get_grade_overview',
                'Whole-class gradebook analytics for a course: the class average per graded item (so you '
                . 'can see which activity/topic is hardest), and a per-student summary (overall, their '
                . 'strongest and weakest item, and whether they look at risk). Figures are authoritative.',
                ['courseid' => ['type' => 'integer', 'description' => 'The course id.']],
                ['courseid']
            );
            $tools[] = self::fn(
                'get_quiz_questions',
                'Read a quiz or exam\'s questions (and, for staff, the answers marked correct) so you can '
                . 'see what it assesses and which topics it covers. Use get_course_contents for the quiz cmid.',
                [
                    'courseid' => ['type' => 'integer', 'description' => 'The course id.'],
                    'cmid'     => ['type' => 'integer', 'description' => 'The quiz course-module id.'],
                ],
                ['courseid', 'cmid']
            );
            $tools[] = self::fn(
                'get_assignment_status',
                'Submission status for an assignment: who has submitted, who is late, and who is still '
                . 'to submit. Omit userid for a class summary; pass a userid for one learner\'s status and '
                . 'any feedback left for them. Use get_course_contents for the assignment cmid.',
                [
                    'courseid' => ['type' => 'integer', 'description' => 'The course id.'],
                    'cmid'     => ['type' => 'integer', 'description' => 'The assignment course-module id.'],
                    'userid'   => ['type' => 'integer', 'description' => 'Optional: one learner\'s id.'],
                ],
                ['courseid', 'cmid']
            );
            $tools[] = self::fn(
                'get_quiz_attempt_analytics',
                'Attempt analytics for a quiz: which questions were missed most often (facility per '
                . 'question, hardest first) and a per-learner attempt summary. Use this to see where the '
                . 'class struggled. Use get_course_contents for the quiz cmid.',
                [
                    'courseid' => ['type' => 'integer', 'description' => 'The course id.'],
                    'cmid'     => ['type' => 'integer', 'description' => 'The quiz course-module id.'],
                ],
                ['courseid', 'cmid']
            );
            $tools[] = self::fn(
                'get_grade_detail',
                'A learner\'s grades in a course WITH the teacher feedback left on each item and whether a '
                . 'grade was manually overridden. Use when asked what feedback a learner received.',
                [
                    'courseid' => ['type' => 'integer', 'description' => 'The course id.'],
                    'userid'   => ['type' => 'integer', 'description' => 'Learner id; omit for the current user.'],
                ],
                ['courseid']
            );
            $tools[] = self::fn(
                'get_gradebook_structure',
                'How the course total is built: the gradebook category tree with each item\'s weight and '
                . 'the aggregation method. Use to explain why a learner\'s overall grade is what it is.',
                ['courseid' => ['type' => 'integer', 'description' => 'The course id.']],
                ['courseid']
            );
            $tools[] = self::fn(
                'get_grading_queue',
                'What is waiting for you to grade across your courses: assignments with submitted work not '
                . 'yet graded. Use for "what do I need to grade?".',
                [],
                []
            );
        }

        if (in_array('participants', $domains, true)) {
            $tools[] = self::fn(
                'get_forum_activity',
                'Forum participation in a course: discussion and post counts, and who is posting most (and '
                . 'who has not posted). Omit cmid for all forums; pass a cmid for one forum.',
                [
                    'courseid' => ['type' => 'integer', 'description' => 'The course id.'],
                    'cmid'     => ['type' => 'integer', 'description' => 'Optional: one forum\'s course-module id.'],
                ],
                ['courseid']
            );
            $tools[] = self::fn(
                'get_enrolment_info',
                'How learners are enrolled in a course: their enrolment date, start/end dates, whether they '
                . 'are active or suspended, and when they last accessed the course. Omit userid for the '
                . 'class; pass a userid for one learner.',
                [
                    'courseid' => ['type' => 'integer', 'description' => 'The course id.'],
                    'userid'   => ['type' => 'integer', 'description' => 'Optional: one learner\'s id.'],
                ],
                ['courseid']
            );
            $tools[] = self::fn(
                'get_student_groups',
                'The groups in a course and who is in each; or, with a userid, the groups one learner '
                . 'belongs to.',
                [
                    'courseid' => ['type' => 'integer', 'description' => 'The course id.'],
                    'userid'   => ['type' => 'integer', 'description' => 'Optional: one learner\'s id.'],
                ],
                ['courseid']
            );
            $tools[] = self::fn(
                'get_student_overview',
                'A single rounded picture of one learner in a course: overall grade, completion, last '
                . 'access, groups, how many assignments are still outstanding, and whether they look at '
                . 'risk. Use this first when asked how a particular learner is doing.',
                [
                    'courseid' => ['type' => 'integer', 'description' => 'The course id.'],
                    'userid'   => ['type' => 'integer', 'description' => 'The learner id.'],
                ],
                ['courseid', 'userid']
            );
            $tools[] = self::fn(
                'get_forum_posts',
                'Read the actual posts in a forum so you can summarise a discussion or spot confusion. '
                . 'Use get_course_contents for the forum cmid. The text is student-authored — summarise it, '
                . 'do not quote at length.',
                [
                    'courseid'     => ['type' => 'integer', 'description' => 'The course id.'],
                    'cmid'         => ['type' => 'integer', 'description' => 'The forum course-module id.'],
                    'discussionid' => ['type' => 'integer', 'description' => 'Optional: one discussion to read.'],
                ],
                ['courseid', 'cmid']
            );
        }

        if (in_array('dates', $domains, true)) {
            $tools[] = self::fn(
                'get_activity_settings',
                'See the key dates for activities in a course — when each opens, is due, and closes. '
                . 'Omit cmid for a compact list of every dated activity; pass a cmid from get_course_contents '
                . 'for one activity\'s dates and whether it has an access restriction.',
                [
                    'courseid' => ['type' => 'integer', 'description' => 'The course id.'],
                    'cmid'     => ['type' => 'integer', 'description' => 'Optional: one activity\'s course-module id.'],
                ],
                ['courseid']
            );
            $tools[] = self::fn(
                'get_upcoming',
                'What is coming up in a course: deadlines and calendar events over the next period, in time '
                . 'order. Use for "what is due soon" questions.',
                [
                    'courseid' => ['type' => 'integer', 'description' => 'The course id.'],
                    'days'     => ['type' => 'integer', 'description' => 'How many days ahead to look (default 30, max 180).'],
                ],
                ['courseid']
            );
        }

        if (in_array('progress', $domains, true)) {
            $tools[] = self::fn(
                'get_completion_overview',
                'Whole-class progress for a course: how much of the completion-tracked work each learner '
                . 'has finished and when they last accessed the course, flagging those who have stalled. Use '
                . 'this for engagement / at-risk questions that grades alone do not answer.',
                ['courseid' => ['type' => 'integer', 'description' => 'The course id.']],
                ['courseid']
            );
            $tools[] = self::fn(
                'get_activity_log',
                'Engagement from the course logs: how often and how recently each learner has been active '
                . 'in the course (event counts over a recent window and last activity). Omit userid for the '
                . 'class; pass a userid for one learner. Requires the standard log store.',
                [
                    'courseid' => ['type' => 'integer', 'description' => 'The course id.'],
                    'userid'   => ['type' => 'integer', 'description' => 'Optional: one learner\'s id.'],
                    'days'     => ['type' => 'integer', 'description' => 'Look-back window in days (default 30, max 180).'],
                ],
                ['courseid']
            );
        }

        if ($conversationid && access_manager::feature_enabled($userid, 'upload')) {
            $tools[] = self::fn(
                'list_attachments',
                'List the files the user has attached to this conversation, with each file\'s read status.',
                [],
                []
            );
            $tools[] = self::fn(
                'read_attachment',
                'Read text extracted from an attached file. Call list_attachments first for the id. '
                . 'Large files are paged — pass offset to continue from where you stopped.',
                [
                    'id'     => ['type' => 'integer', 'description' => 'Attachment id from list_attachments.'],
                    'offset' => ['type' => 'integer', 'description' => 'Character offset to start from (default 0).'],
                    'length' => ['type' => 'integer', 'description' => 'Characters to read (max 50000).'],
                ],
                ['id']
            );
        }

        if ($conversationid && access_manager::feature_enabled($userid, 'export')) {
            $tools[] = self::fn(
                'export_table',
                'Create a downloadable Excel, CSV or PDF file from a table of results you have gathered. '
                . 'Returns a download link to include in your reply. Only export data the user may already see.',
                [
                    'title'   => ['type' => 'string', 'description' => 'A short title; becomes the file name.'],
                    'format'  => ['type' => 'string', 'enum' => ['excel', 'csv', 'pdf'],
                        'description' => 'excel, csv or pdf.'],
                    'columns' => ['type' => 'array', 'items' => ['type' => 'string'],
                        'description' => 'Column header labels, in order.'],
                    'rows'    => ['type' => 'array',
                        'items' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'description' => 'Rows of cell values, each aligned to columns.'],
                ],
                ['title', 'format', 'columns', 'rows']
            );
            $tools[] = self::fn(
                'export_document',
                'Create a downloadable PDF from a short written summary. Returns a download link.',
                [
                    'title' => ['type' => 'string', 'description' => 'Document title and file name.'],
                    'body'  => ['type' => 'string',
                        'description' => 'The summary as simple HTML: p, b, i, ul/li, h2, table.'],
                ],
                ['title', 'body']
            );
        }

        if (in_array('actions', $domains, true)) {
            $tools[] = self::fn(
                'get_action_link',
                'Get a direct link and short steps for a change the user wants to make (edit a grade, '
                . 'edit an activity, view the grader report, a profile or participants). It does NOT make '
                . 'the change and returns a link only if the user already has permission. Call it only when '
                . 'the user asks how or where to do or change something.',
                [
                    'intent'   => ['type' => 'string',
                        'enum' => ['edit_grade', 'edit_grade_item', 'view_grader',
                            'view_profile', 'edit_activity', 'view_participants'],
                        'description' => 'What the user wants to do.'],
                    'courseid' => ['type' => 'integer', 'description' => 'The course id.'],
                    'userid'   => ['type' => 'integer', 'description' => 'Target learner id (edit_grade, view_profile).'],
                    'itemid'   => ['type' => 'integer', 'description' => 'Grade item id (edit_grade_item).'],
                    'cmid'     => ['type' => 'integer', 'description' => 'Activity course-module id (edit_activity).'],
                ],
                ['intent', 'courseid']
            );
        }

        return $tools;
    }

    /**
     * A one-line summary of the conversation's attachments for the system prompt.
     *
     * @param int $conversationid Conversation id.
     * @param int $userid Acting user.
     * @return string Empty when there are none.
     */
    public static function attachment_summary(int $conversationid, int $userid): string {
        $rows = \local_groundedai\local\attachment_manager::list_for($conversationid, $userid);
        $parts = [];
        foreach ($rows as $row) {
            $parts[] = $row->filename . ' [' . $row->status . ', id ' . (int) $row->id . ']';
        }
        return implode('; ', $parts);
    }

    /**
     * Build one tool schema.
     *
     * @param string $name Tool name.
     * @param string $desc Description for the model.
     * @param array $props JSON-schema properties.
     * @param array $required Required property names.
     * @return array
     */
    protected static function fn(string $name, string $desc, array $props, array $required): array {
        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $desc,
                // Cast to object so an empty property set encodes as {} (a valid JSON Schema), not [].
                'parameters' => ['type' => 'object', 'properties' => (object) $props, 'required' => $required],
            ],
        ];
    }

    /**
     * Execute a tool: re-validate the domain, re-check capability, fetch. Returns a JSON-encodable array.
     *
     * @param string $name Tool name requested by the model.
     * @param array $args Decoded arguments.
     * @param int $userid The acting staff user.
     * @param int $conversationid The conversation (scopes attachment tools).
     * @return array
     */
    public static function dispatch(string $name, array $args, int $userid, int $conversationid = 0): array {
        $domains = self::enabled_domains($userid);
        switch ($name) {
            case 'find':
                return self::find($args, $userid, $domains);
            case 'get_course_contents':
                return self::get_course_contents($args, $userid, $domains);
            case 'read_material':
                return self::read_material($args, $userid, $domains);
            case 'get_grades':
                return self::get_grades($args, $userid, $domains);
            case 'get_grade_overview':
                return self::get_grade_overview($args, $userid, $domains);
            case 'get_quiz_questions':
                return self::get_quiz_questions($args, $userid, $domains);
            case 'get_assignment_status':
                return self::get_assignment_status($args, $userid, $domains);
            case 'get_quiz_attempt_analytics':
                return self::get_quiz_attempt_analytics($args, $userid, $domains);
            case 'get_grade_detail':
                return self::get_grade_detail($args, $userid, $domains);
            case 'get_gradebook_structure':
                return self::get_gradebook_structure($args, $userid, $domains);
            case 'get_forum_activity':
                return self::get_forum_activity($args, $userid, $domains);
            case 'get_forum_posts':
                return self::get_forum_posts($args, $userid, $domains);
            case 'get_grading_queue':
                return self::get_grading_queue($args, $userid, $domains);
            case 'get_course_info':
                return self::get_course_info($args, $userid, $domains);
            case 'get_announcements':
                return self::get_announcements($args, $userid, $domains);
            case 'get_enrolment_info':
                return self::get_enrolment_info($args, $userid, $domains);
            case 'get_student_groups':
                return self::get_student_groups($args, $userid, $domains);
            case 'get_student_overview':
                return self::get_student_overview($args, $userid, $domains);
            case 'get_activity_log':
                return self::get_activity_log($args, $userid, $domains);
            case 'get_upcoming':
                return self::get_upcoming($args, $userid, $domains);
            case 'get_activity_settings':
                return self::get_activity_settings($args, $userid, $domains);
            case 'get_completion_overview':
                return self::get_completion_overview($args, $userid, $domains);
            case 'list_attachments':
                return self::list_attachments($conversationid, $userid);
            case 'read_attachment':
                return self::read_attachment($args, $conversationid, $userid);
            case 'export_table':
                return self::export_table($args, $conversationid, $userid);
            case 'export_document':
                return self::export_document($args, $conversationid, $userid);
            case 'get_action_link':
                return self::get_action_link($args, $userid, $domains);
            default:
                return ['error' => 'unknown or disabled tool'];
        }
    }

    /**
     * list_attachments: the files on this conversation and their read status.
     *
     * @param int $conversationid Conversation id.
     * @param int $userid Acting user.
     * @return array
     */
    protected static function list_attachments(int $conversationid, int $userid): array {
        if (!$conversationid || !access_manager::feature_enabled($userid, 'upload')) {
            return ['error' => 'file upload is not available'];
        }
        $out = [];
        foreach (\local_groundedai\local\attachment_manager::list_for($conversationid, $userid) as $row) {
            $out[] = [
                'id'       => (int) $row->id,
                'filename' => $row->filename,
                'type'     => $row->type,
                'status'   => $row->status,
                'chars'    => (int) $row->chars,
            ];
        }
        return ['attachments' => $out];
    }

    /**
     * read_attachment: a chunk of one attached file's extracted text (untrusted reference data).
     *
     * @param array $args Arguments.
     * @param int $conversationid Conversation id (scopes the attachment).
     * @param int $userid Acting user.
     * @return array
     */
    protected static function read_attachment(array $args, int $conversationid, int $userid): array {
        if (!$conversationid || !access_manager::feature_enabled($userid, 'upload')) {
            return ['error' => 'file upload is not available'];
        }
        $attachid = (int) ($args['id'] ?? 0);
        if (!$attachid) {
            return ['error' => 'id is required'];
        }
        $offset = (int) ($args['offset'] ?? 0);
        $length = (int) ($args['length'] ?? \local_groundedai\local\attachment_manager::READ_MAX);
        try {
            $chunk = \local_groundedai\local\attachment_manager::read_chunk(
                $attachid,
                $userid,
                $offset,
                $length,
                $conversationid
            );
        } catch (\moodle_exception $e) {
            return ['error' => 'that attachment is not part of this conversation'];
        }
        if ($chunk['status'] !== 'done') {
            return ['filename' => $chunk['filename'], 'status' => $chunk['status'],
                'note' => 'this file has no readable text yet'];
        }
        return [
            'filename'               => $chunk['filename'],
            'status'                 => 'done',
            'offset'                 => $chunk['offset'],
            'total_chars'            => $chunk['total'],
            'hasmore'                => $chunk['hasmore'],
            // The key name marks this as data, not instructions.
            'untrusted_file_content' => $chunk['text'],
        ];
    }

    /**
     * export_table: render gathered rows to a downloadable Excel/CSV/PDF and return a link.
     *
     * @param array $args Arguments.
     * @param int $conversationid Conversation id.
     * @param int $userid Acting user.
     * @return array
     */
    protected static function export_table(array $args, int $conversationid, int $userid): array {
        if (!$conversationid || !access_manager::feature_enabled($userid, 'export')) {
            return ['error' => 'export is not available'];
        }
        $title = trim((string) ($args['title'] ?? 'export'));
        $format = (string) ($args['format'] ?? 'excel');
        if (!isset(\local_groundedai\local\exporter::TABLE_FORMATS[$format])) {
            $format = 'excel';
        }
        $columns = array_map('strval', (array) ($args['columns'] ?? []));
        $rows = array_map(static function ($r) {
            return array_map(static fn($c) => is_scalar($c) ? (string) $c : '', (array) $r);
        }, (array) ($args['rows'] ?? []));
        if (!$columns || !$rows) {
            return ['error' => 'provide columns and at least one row'];
        }
        try {
            $file = \local_groundedai\local\exporter::table($conversationid, $userid, $title, $columns, $rows, $format);
        } catch (\required_capability_exception $e) {
            return ['error' => 'you do not have permission to export records in this course'];
        }
        return ['filename' => $file->get_filename(),
            'url' => \local_groundedai\local\exporter::file_url($file)->out(false)];
    }

    /**
     * export_document: render a short HTML summary to a downloadable PDF and return a link.
     *
     * @param array $args Arguments.
     * @param int $conversationid Conversation id.
     * @param int $userid Acting user.
     * @return array
     */
    protected static function export_document(array $args, int $conversationid, int $userid): array {
        if (!$conversationid || !access_manager::feature_enabled($userid, 'export')) {
            return ['error' => 'export is not available'];
        }
        $title = trim((string) ($args['title'] ?? 'summary'));
        $body = (string) ($args['body'] ?? '');
        if (trim($body) === '') {
            return ['error' => 'provide the document body'];
        }
        try {
            $file = \local_groundedai\local\exporter::document($conversationid, $userid, $title, $body);
        } catch (\required_capability_exception $e) {
            return ['error' => 'you do not have permission to export records in this course'];
        }
        return ['filename' => $file->get_filename(),
            'url' => \local_groundedai\local\exporter::file_url($file)->out(false)];
    }

    /**
     * Fetch a course record once per request. get_course() is not request-cached unless the id is the
     * site or the current $PAGE course, and the AJAX send path sets neither — so without this the same
     * course is re-read from the DB on every tool call across the multi-round answer loop. The clone
     * matches get_course()'s own fresh-object semantics so callers never share a mutable instance.
     *
     * @param int $courseid Course id.
     * @return \stdClass The course record.
     */
    protected static function course(int $courseid): \stdClass {
        if (!array_key_exists($courseid, self::$coursecache)) {
            self::$coursecache[$courseid] = get_course($courseid);   // Throws on a bad id; nothing cached.
        }
        return clone self::$coursecache[$courseid];
    }

    /**
     * Fetch a course's full grade-item set once per request. grade_item::fetch_all() is not cached and
     * several grade tools re-run the identical query within one answer; the items are only read here.
     *
     * @param int $courseid Course id.
     * @return array grade_item objects keyed by id (possibly empty).
     */
    protected static function grade_items(int $courseid): array {
        if (!array_key_exists($courseid, self::$gradeitemcache)) {
            self::$gradeitemcache[$courseid] = \grade_item::fetch_all(['courseid' => $courseid]) ?: [];
        }
        return self::$gradeitemcache[$courseid];
    }

    /**
     * The user ids a caller may see in a course, honouring separate-groups mode.
     *
     * The result is invariant for a (course, user) within one request, so it is memoised — the answer
     * loop calls this from ~12 tools for the same course and user. Read-only; the per-tool capability
     * re-checks run in each tool before this is reached, so the wall is unaffected.
     *
     * @param int $courseid Course id.
     * @param \context $context Course context.
     * @param int $userid Acting user.
     * @return array|null null = no restriction; otherwise the only visible user ids ([] = none).
     */
    protected static function group_restricted_userids(int $courseid, \context $context, int $userid): ?array {
        $key = $courseid . ':' . $userid;
        if (array_key_exists($key, self::$grouprestrictcache)) {
            return self::$grouprestrictcache[$key];
        }
        $course = self::course($courseid);
        // The groupmode comes back from the DB as a string, so cast before the strict compare.
        if (
            (int) groups_get_course_groupmode($course) !== SEPARATEGROUPS
                || has_capability('moodle/site:accessallgroups', $context, $userid)
        ) {
            return self::$grouprestrictcache[$key] = null;
        }
        $mygroups = groups_get_all_groups($courseid, $userid, 0, 'g.id');
        if (!$mygroups) {
            return self::$grouprestrictcache[$key] = [];   // No group under separate groups: no others visible.
        }
        // The groups_get_groups_members() call returns users keyed by id (its 2nd arg is an extra-fields
        // ARRAY, not a field string), so the keys are exactly the visible user ids.
        $members = groups_get_groups_members(array_keys($mygroups));
        return self::$grouprestrictcache[$key] = array_map('intval', array_keys($members));
    }

    /**
     * The caller's effective course scope, from their access grants, as a reusable guard. A grant can be
     * 'all' (no tightening), 'enrolled' (only courses they are enrolled in), or a specific course. The
     * reachable set is the union of their active grants. Returns a recoverable error to return verbatim,
     * or null when the course is reachable. Tightening only — it runs AFTER can_access_course() in gate(),
     * so it can never grant a course Moodle denies.
     *
     * @param \context $context The course context.
     * @param int $userid The acting user.
     * @return array|null An {error} to return, or null if allowed.
     */
    protected static function scope_blocks(\context $context, int $userid): ?array {
        $eff = access_manager::effective_course_access($userid);
        if ($eff['all']) {
            return null;
        }
        $courseid = (int) $context->instanceid;
        if ($eff['enrolled'] && is_enrolled($context, $userid)) {
            return null;
        }
        if (in_array($courseid, $eff['courseids'], true)) {
            return null;
        }
        return ['error' => get_string('scope_blocked', 'local_groundedai')];
    }

    /**
     * Shared read gate used by the data tools: confirm the domain is enabled, the course exists and the
     * caller may see it, and (optionally) that they hold a course-level capability. Returns the course
     * and context to the caller, or a recoverable {error} that the tool returns verbatim. Centralising
     * this keeps every tool's "wall" identical and in one place — there is still no write path anywhere.
     *
     * @param string $domain The data domain the tool belongs to.
     * @param array $domains The enabled domains.
     * @param int $courseid The requested course.
     * @param int $userid The acting user.
     * @param string|null $capability A course-context capability to require, or null for course access only.
     * @return array ['course'=>stdClass, 'context'=>context_course] on success, or ['error'=>string].
     */
    protected static function gate(
        string $domain,
        array $domains,
        int $courseid,
        int $userid,
        ?string $capability = null
    ): array {
        if (!in_array($domain, $domains, true)) {
            return ['error' => 'the ' . $domain . ' data is not enabled'];
        }
        if (!$courseid) {
            return ['error' => 'courseid is required'];
        }
        try {
            $course = self::course($courseid);
        } catch (\moodle_exception $e) {
            return ['error' => 'that course does not exist'];
        }
        if (!can_access_course($course, $userid)) {
            return ['error' => 'you may not view this course'];
        }
        $context = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$context) {
            return ['error' => 'that course does not exist'];
        }
        if ($blocked = self::scope_blocks($context, $userid)) {
            return $blocked;
        }
        if ($capability !== null && !has_capability($capability, $context, $userid)) {
            return ['error' => 'you do not have permission for that in this course'];
        }
        return ['course' => $course, 'context' => $context];
    }

    /**
     * Attach display names to rows keyed/containing a user id, in one query (no per-row lookups).
     *
     * @param array $rows Rows that each carry an 'id' (user id); modified in place to add 'name'.
     * @return array The same rows with a 'name' added.
     */
    protected static function with_names(array $rows): array {
        global $DB;
        if (!$rows) {
            return $rows;
        }
        [$insql, $params] = $DB->get_in_or_equal(array_column($rows, 'id'), SQL_PARAMS_NAMED);
        $users = $DB->get_records_select(
            'user',
            "id $insql",
            $params,
            '',
            'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename'
        );
        foreach ($rows as &$row) {
            $row['name'] = isset($users[$row['id']]) ? fullname($users[$row['id']]) : ('user ' . $row['id']);
        }
        unset($row);
        return $rows;
    }

    /**
     * find: courses or people the user may view.
     *
     * @param array $args Arguments.
     * @param int $userid Acting user.
     * @param array $domains Enabled domains.
     * @return array
     */
    protected static function find(array $args, int $userid, array $domains): array {
        $type = (string) ($args['type'] ?? '');
        $query = trim((string) ($args['query'] ?? ''));

        if ($type === 'courses') {
            if (!in_array('courses', $domains, true)) {
                return ['error' => 'the courses domain is disabled'];
            }
            $courses = enrol_get_all_users_courses($userid, true, 'id, fullname, shortname');
            $out = [];
            foreach ($courses as $course) {
                $name = format_string($course->fullname);
                if ($query !== '' && stripos($name, $query) === false) {
                    continue;
                }
                $out[] = ['id' => (int) $course->id, 'name' => $name, 'shortname' => $course->shortname];
            }
            // Keep the picker consistent with the wall: a course-scoped grantee sees only their courses.
            $eff = access_manager::effective_course_access($userid);
            if (!$eff['all']) {
                $out = array_values(array_filter(
                    $out,
                    static fn($c) => $eff['enrolled'] || in_array($c['id'], $eff['courseids'], true)
                ));
            }
            return ['courses' => array_slice($out, 0, 50)];
        }

        if ($type === 'people') {
            // Route through the shared gate so people search enforces can_access_course() too, not just
            // the viewparticipants capability (a category-level role can inherit the capability on a
            // course the holder cannot actually access).
            $gate = self::gate(
                'participants',
                $domains,
                (int) ($args['courseid'] ?? 0),
                $userid,
                'moodle/course:viewparticipants'
            );
            if (isset($gate['error'])) {
                return $gate;
            }
            $courseid = (int) $gate['course']->id;
            $context = $gate['context'];
            $restrict = self::group_restricted_userids($courseid, $context, $userid);
            // The id and the name fields fullname() needs. Email is added ONLY when a grant enables the
            // email feature for this user; by default no contact PII is pulled into memory.
            $withemail = access_manager::feature_enabled($userid, 'email');
            $fields = 'u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, '
                . 'u.middlename, u.alternatename';
            if ($withemail) {
                $fields .= ', u.email';
            }
            $users = get_enrolled_users($context, '', 0, $fields, null, 0, 50);
            $rolenames = role_get_names($context);
            $userroles = get_users_roles($context, array_keys($users), false);
            $out = [];
            foreach ($users as $user) {
                if ($restrict !== null && !in_array((int) $user->id, $restrict, true)) {
                    continue;   // Separate groups: hide members the caller may not see.
                }
                $name = fullname($user);
                if ($query !== '' && stripos($name, $query) === false) {
                    continue;
                }
                $roles = [];
                foreach (($userroles[$user->id] ?? []) as $ra) {
                    if (isset($rolenames[$ra->roleid])) {
                        $roles[] = $rolenames[$ra->roleid]->localname;
                    }
                }
                $row = ['id' => (int) $user->id, 'name' => $name, 'roles' => array_values(array_unique($roles))];
                if ($withemail && !empty($user->email)) {
                    $row['email'] = $user->email;
                }
                $out[] = $row;
            }
            return ['courseid' => $courseid, 'participants' => $out];
        }

        return ['error' => 'unknown find type'];
    }

    /**
     * get_grades: a learner's grades in a course, honouring hidden-grade visibility.
     *
     * @param array $args Arguments.
     * @param int $userid Acting user.
     * @param array $domains Enabled domains.
     * @return array
     */
    protected static function get_grades(array $args, int $userid, array $domains): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');

        $courseid = (int) ($args['courseid'] ?? 0);
        // Shared gate: domain, course existence, can_access_course() and per-grant scope — same wall as
        // every other tool. The grade-specific view/viewall checks below still apply on top.
        $gate = self::gate('grades', $domains, $courseid, $userid);
        if (isset($gate['error'])) {
            return $gate;
        }
        $context = $gate['context'];
        $target = (int) ($args['userid'] ?? $userid);

        if ($target === $userid) {
            if (
                !has_capability('moodle/grade:view', $context, $userid)
                    && !has_capability('moodle/grade:viewall', $context, $userid)
            ) {
                return ['error' => 'you may not view grades in this course'];
            }
        } else if (!has_capability('moodle/grade:viewall', $context, $userid)) {
            return ['error' => 'you may not view other learners\' grades in this course'];
        }

        // Separate groups: a grade:viewall holder without accessallgroups may only see their own groups.
        if ($target !== $userid) {
            $restrict = self::group_restricted_userids($courseid, $context, $userid);
            if ($restrict !== null && !in_array($target, $restrict, true)) {
                return ['error' => 'you may not view this learner\'s grades (they are in another group)'];
            }
        }

        $items = self::grade_items($courseid);
        if (!$items) {
            return ['courseid' => $courseid, 'userid' => $target, 'grades' => [], 'note' => 'no grade items in this course'];
        }

        // Bulk-load this learner's grades for the visible items in one query (not a fetch per item).
        $byitem = [];
        $visibleids = [];
        foreach ($items as $item) {
            if (!$item->is_hidden()) {
                $visibleids[] = (int) $item->id;
            }
        }
        if ($visibleids) {
            [$insql, $p] = $DB->get_in_or_equal($visibleids, SQL_PARAMS_NAMED);
            $p['userid'] = $target;
            foreach ($DB->get_records_select('grade_grades', "userid = :userid AND itemid $insql", $p) as $r) {
                $byitem[(int) $r->itemid] = new \grade_grade($r, false);
            }
        }

        $grades = [];
        foreach ($items as $item) {
            if ($item->is_hidden()) {
                continue;
            }
            $grade = $byitem[$item->id] ?? null;
            if ($grade) {
                $grade->grade_item = $item;
                if ($grade->is_hidden()) {
                    continue;
                }
            }
            $final = $grade ? $grade->finalgrade : null;
            $display = ($final === null) ? '-' : grade_format_gradevalue($final, $item, true);
            $grades[] = ['item' => $item->get_name(), 'grade' => $display];
        }
        return ['courseid' => $courseid, 'userid' => $target, 'grades' => $grades];
    }

    /**
     * get_course_contents: a course's sections (topics) and the activities within each.
     *
     * @param array $args Arguments.
     * @param int $userid Acting user.
     * @param array $domains Enabled domains.
     * @return array
     */
    protected static function get_course_contents(array $args, int $userid, array $domains): array {
        $gate = self::gate('courses', $domains, (int) ($args['courseid'] ?? 0), $userid);
        if (isset($gate['error'])) {
            return $gate;
        }
        $course = $gate['course'];
        $courseid = (int) $course->id;
        $modinfo = get_fast_modinfo($course, $userid);
        $bysection = [];
        foreach ($modinfo->get_cms() as $cm) {
            if (!$cm->uservisible) {
                continue;
            }
            $bysection[$cm->sectionnum][] = [
                'cmid' => (int) $cm->id,
                'name' => $cm->get_formatted_name(),
                'type' => $cm->modname,
            ];
        }
        $sections = [];
        foreach ($modinfo->get_section_info_all() as $section) {
            if (!$section->uservisible) {
                continue;
            }
            $activities = $bysection[$section->section] ?? [];
            $summary = trim(html_to_text((string) $section->summary, 0, false));
            if (!$activities && $summary === '') {
                continue;
            }
            $sections[] = [
                'name'       => get_section_name($course, $section),
                'summary'    => \core_text::substr($summary, 0, 300),
                'activities' => $activities,
            ];
        }
        return ['courseid' => $courseid, 'sections' => $sections];
    }

    /**
     * read_material: the readable text of one learning resource the caller may view.
     *
     * @param array $args Arguments.
     * @param int $userid Acting user.
     * @param array $domains Enabled domains.
     * @return array
     */
    protected static function read_material(array $args, int $userid, array $domains): array {
        $gate = self::gate('courses', $domains, (int) ($args['courseid'] ?? 0), $userid);
        if (isset($gate['error'])) {
            return $gate;
        }
        $cmid = (int) ($args['cmid'] ?? 0);
        if (!$cmid) {
            return ['error' => 'courseid and cmid are required'];
        }
        $course = $gate['course'];
        $modinfo = get_fast_modinfo($course, $userid);
        try {
            $cm = $modinfo->get_cm($cmid);
        } catch (\moodle_exception $e) {
            return ['error' => 'that activity is not part of this course'];
        }
        if (!$cm->uservisible) {
            return ['error' => 'you may not view that activity'];
        }
        $text = \local_groundedai\local\content_extractor::read_module($cm, $userid);
        if ($text === '') {
            return ['cmid' => $cmid, 'name' => $cm->get_formatted_name(), 'type' => $cm->modname,
                'note' => 'this activity has no extractable text (for example a video, image or external tool)'];
        }
        // Page the body so the common "summarise this topic" case stays small, while a genuinely long
        // document can still be read in chunks on demand (mirrors read_attachment's offset/length).
        $total = \core_text::strlen($text);
        $offset = max(0, min((int) ($args['offset'] ?? 0), $total));
        $length = max(1, min((int) ($args['length'] ?? self::MATERIAL_READ_DEFAULT), self::MATERIAL_READ_MAX));
        $slice = \core_text::substr($text, $offset, $length);
        return [
            'cmid'        => $cmid,
            'name'        => $cm->get_formatted_name(),
            'type'        => $cm->modname,
            'offset'      => $offset,
            'total_chars' => $total,
            'hasmore'     => ($offset + \core_text::strlen($slice)) < $total,
            'text'        => $slice,
        ];
    }

    /**
     * get_grade_overview: whole-class gradebook analytics for a course.
     *
     * @param array $args Arguments.
     * @param int $userid Acting user.
     * @param array $domains Enabled domains.
     * @return array
     */
    protected static function get_grade_overview(array $args, int $userid, array $domains): array {
        global $CFG;
        if (!in_array('grades', $domains, true)) {
            return ['error' => 'the grades domain is disabled'];
        }
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->dirroot . '/grade/lib.php');   // The graded_users_iterator class lives here.

        $courseid = (int) ($args['courseid'] ?? 0);
        $gate = self::gate('grades', $domains, $courseid, $userid, 'moodle/grade:viewall');
        if (isset($gate['error'])) {
            return $gate;
        }
        $context = $gate['context'];

        // Visible grade items to load: per-activity leaves, plus the course total.
        $activity = [];
        $courseitem = null;
        $loaditems = [];
        foreach (self::grade_items($courseid) as $item) {
            if ($item->is_hidden()) {
                continue;
            }
            if ($item->itemtype === 'course') {
                $courseitem = $item;
                $loaditems[$item->id] = $item;
            } else if (in_array($item->itemtype, ['mod', 'manual'], true) && (float) $item->grademax > 0) {
                $activity[$item->id] = $item;
                $loaditems[$item->id] = $item;
            }
        }
        if (!$activity) {
            return ['courseid' => $courseid, 'note' => 'no graded activities in this course'];
        }

        // Walk the gradebook's own user set (gradebook-role, active enrolments), honouring groups.
        // The iterator loads each learner's grades in one pass, so no per-item queries are needed.
        $restrict = self::group_restricted_userids($courseid, $context, $userid);
        $course = self::course($courseid);
        $gui = new \graded_users_iterator($course, $loaditems);
        $gui->require_active_enrolment(true);
        $byuser = [];
        $names = [];
        $truncated = false;
        $gui->init();
        // The close() call frees two server-side recordsets; a finally runs it even if the loop throws.
        try {
            while ($userdata = $gui->next_user()) {
                $uid = (int) $userdata->user->id;
                if ($restrict !== null && !in_array($uid, $restrict, true)) {
                    continue;
                }
                if (count($byuser) >= 150) {
                    $truncated = true;
                    break;
                }
                $names[$uid] = fullname($userdata->user);
                $byuser[$uid] = [];
                foreach ($userdata->grades as $itemid => $grade) {
                    if (!empty($grade->hidden) || $grade->finalgrade === null) {
                        continue;
                    }
                    $byuser[$uid][(int) $itemid] = (float) $grade->finalgrade;
                }
            }
        } finally {
            $gui->close();
        }
        if (!$byuser) {
            return ['courseid' => $courseid, 'note' => 'no learners with grades in scope'];
        }

        // Per-item class stats (hardest first).
        $itemstats = [];
        foreach ($activity as $itemid => $item) {
            $max = (float) $item->grademax;
            $passmark = ((float) $item->gradepass > 0) ? (float) $item->gradepass : $max * 0.5;
            $pcts = [];
            $below = 0;
            foreach ($byuser as $grades) {
                if (!isset($grades[$itemid])) {
                    continue;
                }
                $pcts[] = $grades[$itemid] / $max * 100;
                if ($grades[$itemid] < $passmark) {
                    $below++;
                }
            }
            if (!$pcts) {
                continue;
            }
            $itemstats[] = [
                'item'        => $item->get_name(),
                'average_pct' => round(array_sum($pcts) / count($pcts), 1),
                'graded'      => count($pcts),
                'below_pass'  => $below,
            ];
        }
        usort($itemstats, static fn($a, $b) => $a['average_pct'] <=> $b['average_pct']);

        // Per-student summary.
        $students = [];
        $atrisk = 0;
        foreach ($byuser as $uid => $grades) {
            $best = null;
            $worst = null;
            $pcts = [];
            foreach ($activity as $itemid => $item) {
                if (!isset($grades[$itemid])) {
                    continue;
                }
                $pct = round($grades[$itemid] / (float) $item->grademax * 100, 1);
                $pcts[] = $pct;
                if ($best === null || $pct > $best['pct']) {
                    $best = ['name' => $item->get_name(), 'pct' => $pct];
                }
                if ($worst === null || $pct < $worst['pct']) {
                    $worst = ['name' => $item->get_name(), 'pct' => $pct];
                }
            }
            if ($courseitem && isset($grades[(int) $courseitem->id]) && (float) $courseitem->grademax > 0) {
                $overall = round($grades[(int) $courseitem->id] / (float) $courseitem->grademax * 100, 1);
            } else {
                $overall = $pcts ? round(array_sum($pcts) / count($pcts), 1) : null;
            }
            $risk = ($overall !== null && $overall < 50);
            if ($risk) {
                $atrisk++;
            }
            $students[] = [
                'id'          => (int) $uid,
                'name'        => $names[$uid] ?? ('user ' . $uid),
                'overall_pct' => $overall,
                'strongest'   => $best ? $best['name'] . ' (' . $best['pct'] . '%)' : null,
                'weakest'     => $worst ? $worst['name'] . ' (' . $worst['pct'] . '%)' : null,
                'at_risk'     => $risk,
            ];
        }

        $result = [
            'courseid'            => $courseid,
            'student_count'       => count($students),
            'at_risk_count'       => $atrisk,
            'items_hardest_first' => $itemstats,
            'students'            => $students,
            'note'               => 'Averages are percentages of each item\'s maximum. "At risk" = overall '
                . 'below 50%. Figures are from the gradebook; interpret, do not recompute them.',
        ];
        if ($truncated) {
            $result['truncated'] = 'Only the first 150 learners are included.';
        }
        return $result;
    }

    /**
     * get_quiz_questions: a quiz/exam's questions and (for staff) the answers marked correct.
     *
     * @param array $args Arguments.
     * @param int $userid Acting user.
     * @param array $domains Enabled domains.
     * @return array
     */
    protected static function get_quiz_questions(array $args, int $userid, array $domains): array {
        global $DB;
        $courseid = (int) ($args['courseid'] ?? 0);
        $cmid = (int) ($args['cmid'] ?? 0);
        if (!$cmid) {
            return ['error' => 'courseid and cmid are required'];
        }
        // Gate on the course first (domain, access, per-grant scope); the module-level capability is
        // checked below once the cm is resolved.
        $gate = self::gate('grades', $domains, $courseid, $userid);
        if (isset($gate['error'])) {
            return $gate;
        }
        $course = $gate['course'];
        $modinfo = get_fast_modinfo($course, $userid);
        try {
            $cm = $modinfo->get_cm($cmid);
        } catch (\moodle_exception $e) {
            return ['error' => 'that activity is not part of this course'];
        }
        if ($cm->modname !== 'quiz') {
            return ['error' => 'that activity is not a quiz'];
        }
        if (!$cm->uservisible) {
            return ['error' => 'you may not view that quiz'];
        }
        $context = \context_module::instance($cmid);
        if (
            !has_capability('mod/quiz:viewreports', $context, $userid)
                && !has_capability('moodle/question:viewall', $context, $userid)
        ) {
            return ['error' => 'you may not view this quiz\'s questions'];
        }

        $structure = \mod_quiz\question\bank\qbank_helper::get_question_structure($cm->instance, $context);
        // First pass: the slots that will render (same 100 cap) and their question ids.
        $slots = [];
        $qids = [];
        foreach ($structure as $slot) {
            if (count($slots) >= 100) {
                break;
            }
            $slots[] = $slot;
            if (!empty($slot->questionid)) {
                $qids[] = (int) $slot->questionid;
            }
        }
        // One bulk fetch of all answer options, grouped in memory (not a query per question).
        $byq = [];
        if ($qids) {
            [$insql, $inparams] = $DB->get_in_or_equal($qids, SQL_PARAMS_NAMED);
            $arows = $DB->get_records_select(
                'question_answers',
                "question $insql",
                $inparams,
                'question, id',
                'id, question, answer, fraction'
            );
            foreach ($arows as $a) {
                $byq[(int) $a->question][] = $a;
            }
        }
        $questions = [];
        foreach ($slots as $slot) {
            if (empty($slot->questionid)) {
                $questions[] = ['slot' => (int) ($slot->slot ?? 0), 'type' => 'random',
                    'question' => 'A random question drawn from a question-bank category.'];
                continue;
            }
            $qtext = trim(html_to_text((string) ($slot->questiontext ?? ''), 0, false));
            $qtext = trim((string) preg_replace('/@@PLUGINFILE@@\S*/', '', $qtext));
            // Answer options live in question_answers for every answerable qtype (empty for essays).
            $answers = [];
            foreach (($byq[(int) $slot->questionid] ?? []) as $answer) {
                if (count($answers) >= 20) {
                    break;   // Bound pathological option counts (e.g. many wildcard shortanswer patterns).
                }
                $text = trim(html_to_text((string) $answer->answer, 0, false));
                if ($text === '') {
                    continue;
                }
                $answers[] = ['text' => \core_text::substr($text, 0, 500), 'correct' => ((float) $answer->fraction > 0)];
            }
            $questions[] = [
                'slot'     => (int) ($slot->slot ?? 0),
                'name'     => $slot->name ?? '',
                'type'     => $slot->qtype ?? '',
                'mark'     => isset($slot->maxmark) ? (float) $slot->maxmark : null,
                'question' => \core_text::substr($qtext, 0, 2000),
                'answers'  => $answers,
            ];
        }
        return [
            'courseid'  => $courseid,
            'cmid'      => $cmid,
            'quiz'      => $cm->get_formatted_name(),
            'questions' => $questions,
            'note'      => 'Answers flagged correct come straight from the question bank. This is the '
                . 'exam content itself — read-only; do not reveal it to learners.',
        ];
    }

    /**
     * get_assignment_status: submission state for an assignment — a class summary, or one learner's status
     * with feedback. Reads the latest submission + grade rows directly (two queries) rather than loading
     * the full assign API per user, so it stays cheap on large cohorts.
     *
     * @param array $args Arguments.
     * @param int $userid Acting user.
     * @param array $domains Enabled domains.
     * @return array
     */
    protected static function get_assignment_status(array $args, int $userid, array $domains): array {
        global $DB;
        $courseid = (int) ($args['courseid'] ?? 0);
        $cmid = (int) ($args['cmid'] ?? 0);
        if (!$cmid) {
            return ['error' => 'courseid and cmid are required'];
        }
        // Gate on the course first (domain, access, per-grant scope); the module-level capability is
        // checked below once the cm is resolved.
        $gate = self::gate('grades', $domains, $courseid, $userid);
        if (isset($gate['error'])) {
            return $gate;
        }
        $course = $gate['course'];
        $modinfo = get_fast_modinfo($course, $userid);
        try {
            $cm = $modinfo->get_cm($cmid);
        } catch (\moodle_exception $e) {
            return ['error' => 'that activity is not part of this course'];
        }
        if ($cm->modname !== 'assign') {
            return ['error' => 'that activity is not an assignment'];
        }
        if (!$cm->uservisible) {
            return ['error' => 'you may not view that assignment'];
        }
        $context = \context_module::instance($cmid);
        if (!has_capability('mod/assign:grade', $context, $userid)) {
            return ['error' => 'you may not view submissions for this assignment'];
        }
        $assign = $DB->get_record('assign', ['id' => $cm->instance], 'id, name, duedate', MUST_EXIST);
        $target = (int) ($args['userid'] ?? 0);
        $restrict = self::group_restricted_userids($courseid, $context, $userid);

        $subs = $DB->get_records(
            'assign_submission',
            ['assignment' => $assign->id, 'latest' => 1],
            '',
            'userid, status, timemodified'
        );
        $gradeby = [];
        foreach ($DB->get_records('assign_grades', ['assignment' => $assign->id], 'id DESC', 'id, userid, grade') as $g) {
            if (!isset($gradeby[(int) $g->userid])) {
                $gradeby[(int) $g->userid] = $g;   // Most recent grade row wins (id DESC).
            }
        }
        $islate = static function ($sub) use ($assign): bool {
            return (int) $assign->duedate > 0 && $sub && $sub->status === 'submitted'
                && (int) $sub->timemodified > (int) $assign->duedate;
        };
        $isgraded = static function ($g): bool {
            return $g && $g->grade !== null && (float) $g->grade >= 0;
        };

        if ($target) {
            if ($restrict !== null && !in_array($target, $restrict, true)) {
                return ['error' => 'you may not view this learner (they are in another group)'];
            }
            $sub = $subs[$target] ?? null;
            $g = $gradeby[$target] ?? null;
            $feedback = '';
            if ($g) {
                $fb = $DB->get_record(
                    'assignfeedback_comments',
                    ['assignment' => $assign->id, 'grade' => $g->id],
                    'commenttext',
                    IGNORE_MISSING
                );
                if ($fb) {
                    $feedback = trim(html_to_text((string) $fb->commenttext, 0, false));
                }
            }
            return [
                'courseid' => $courseid, 'cmid' => $cmid, 'assignment' => format_string($assign->name),
                'userid' => $target,
                'status' => $sub->status ?? 'none',
                'submitted_at' => ($sub && $sub->status === 'submitted') ? date('c', (int) $sub->timemodified) : null,
                'late' => $islate($sub),
                'graded' => $isgraded($g),
                'grade' => $isgraded($g) ? round((float) $g->grade, 2) : null,
                'feedback' => $feedback !== '' ? \core_text::substr($feedback, 0, 2000) : null,
            ];
        }

        $enrolled = get_enrolled_users(
            $context,
            'mod/assign:submit',
            0,
            'u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename',
            null,
            0,
            0,
            true
        );
        $students = [];
        $counts = ['submitted' => 0, 'draft' => 0, 'none' => 0, 'late' => 0, 'graded' => 0];
        $truncated = false;
        foreach ($enrolled as $u) {
            $uid = (int) $u->id;
            if ($restrict !== null && !in_array($uid, $restrict, true)) {
                continue;
            }
            if (count($students) >= 150) {
                $truncated = true;
                break;
            }
            $sub = $subs[$uid] ?? null;
            $status = $sub->status ?? 'none';
            $late = $islate($sub);
            $graded = $isgraded($gradeby[$uid] ?? null);
            $counts[$status === 'submitted' ? 'submitted' : ($status === 'draft' ? 'draft' : 'none')]++;
            if ($late) {
                $counts['late']++;
            }
            if ($graded) {
                $counts['graded']++;
            }
            $students[] = ['id' => $uid, 'name' => fullname($u), 'status' => $status,
                'late' => $late, 'graded' => $graded];
        }
        $result = [
            'courseid' => $courseid, 'cmid' => $cmid, 'assignment' => format_string($assign->name),
            'due' => (int) $assign->duedate > 0 ? date('c', (int) $assign->duedate) : null,
            'totals' => $counts, 'students' => $students,
            'note' => 'status: submitted | draft | none. "late" = submitted after the due date.',
        ];
        if ($truncated) {
            $result['truncated'] = 'Only the first 150 learners are included.';
        }
        return $result;
    }

    /**
     * get_quiz_attempt_analytics: which questions were missed most (facility per slot, hardest first) and
     * a per-learner attempt summary. Two grouped queries over finished attempts — no per-attempt loads.
     *
     * @param array $args Arguments.
     * @param int $userid Acting user.
     * @param array $domains Enabled domains.
     * @return array
     */
    protected static function get_quiz_attempt_analytics(array $args, int $userid, array $domains): array {
        global $DB;
        $courseid = (int) ($args['courseid'] ?? 0);
        $cmid = (int) ($args['cmid'] ?? 0);
        if (!$cmid) {
            return ['error' => 'courseid and cmid are required'];
        }
        // Gate on the course first (domain, access, per-grant scope); the module-level capability is
        // checked below once the cm is resolved.
        $gate = self::gate('grades', $domains, $courseid, $userid);
        if (isset($gate['error'])) {
            return $gate;
        }
        $course = $gate['course'];
        $modinfo = get_fast_modinfo($course, $userid);
        try {
            $cm = $modinfo->get_cm($cmid);
        } catch (\moodle_exception $e) {
            return ['error' => 'that activity is not part of this course'];
        }
        if ($cm->modname !== 'quiz') {
            return ['error' => 'that activity is not a quiz'];
        }
        if (!$cm->uservisible) {
            return ['error' => 'you may not view that quiz'];
        }
        $context = \context_module::instance($cmid);
        if (!has_capability('mod/quiz:viewreports', $context, $userid)) {
            return ['error' => 'you may not view this quiz\'s reports'];
        }
        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], 'id, name, sumgrades, grade', MUST_EXIST);
        $restrict = self::group_restricted_userids($courseid, $context, $userid);

        $attemptrows = $DB->get_records_sql(
            "SELECT userid, COUNT(*) AS attempts, MAX(sumgrades) AS bestsum
               FROM {quiz_attempts}
              WHERE quiz = :quizid AND state = 'finished' AND preview = 0
           GROUP BY userid",
            ['quizid' => $quiz->id]
        );
        $scale = ((float) $quiz->sumgrades > 0) ? ((float) $quiz->grade / (float) $quiz->sumgrades) : 0;
        $students = [];
        $pcts = [];
        $truncated = false;
        foreach ($attemptrows as $row) {
            $uid = (int) $row->userid;
            if ($restrict !== null && !in_array($uid, $restrict, true)) {
                continue;
            }
            if (count($students) >= 150) {
                $truncated = true;
                break;
            }
            $grade = ($row->bestsum !== null) ? round((float) $row->bestsum * $scale, 2) : null;
            $pct = ((float) $quiz->grade > 0 && $grade !== null) ? round($grade / (float) $quiz->grade * 100, 1) : null;
            if ($pct !== null) {
                $pcts[] = $pct;
            }
            $students[] = ['id' => $uid, 'attempts' => (int) $row->attempts, 'best_grade' => $grade, 'best_pct' => $pct];
        }
        $students = self::with_names($students);

        // Per-question facility: average of the final graded fraction, grouped by slot. Under separate
        // groups, restrict to the caller's visible learners so the aggregate never spans other groups.
        $facility = [];
        $restrictsql = '';
        $facilityparams = ['quizid' => $quiz->id];
        $runfacility = true;
        if ($restrict !== null) {
            if (!$restrict) {
                $runfacility = false;   // Caller in no group: no cross-group aggregate.
            } else {
                [$fin, $fp] = $DB->get_in_or_equal($restrict, SQL_PARAMS_NAMED, 'ruid');
                $restrictsql = " AND quiza.userid $fin";
                $facilityparams += $fp;
            }
        }
        if ($runfacility) {
            $facility = $DB->get_records_sql(
                "SELECT qa.slot AS slot, AVG(qas.fraction) AS avgfraction, COUNT(qas.fraction) AS answered
                   FROM {quiz_attempts} quiza
                   JOIN {question_attempts} qa ON qa.questionusageid = quiza.uniqueid
                   JOIN {question_attempt_steps} qas ON qas.questionattemptid = qa.id
                    AND qas.sequencenumber = (SELECT MAX(s2.sequencenumber)
                                                FROM {question_attempt_steps} s2
                                               WHERE s2.questionattemptid = qa.id)
                  WHERE quiza.quiz = :quizid AND quiza.state = 'finished' AND quiza.preview = 0" . $restrictsql . "
               GROUP BY qa.slot
               ORDER BY avgfraction ASC",
                $facilityparams
            );
        }
        $slotnames = [];
        try {
            foreach (\mod_quiz\question\bank\qbank_helper::get_question_structure($cm->instance, $context) as $slot) {
                $slotnames[(int) ($slot->slot ?? 0)] = $slot->name ?? '';
            }
        } catch (\Throwable $e) {
            $slotnames = [];
        }
        $questions = [];
        foreach ($facility as $f) {
            if (count($questions) >= 100) {
                break;
            }
            if ($f->avgfraction === null) {
                continue;
            }
            $questions[] = [
                'slot' => (int) $f->slot,
                'name' => $slotnames[(int) $f->slot] ?? '',
                'facility_pct' => round((float) $f->avgfraction * 100, 1),
                'responses' => (int) $f->answered,
            ];
        }

        $result = [
            'courseid' => $courseid, 'cmid' => $cmid, 'quiz' => format_string($quiz->name),
            'learners_attempted' => count($students),
            'class_average_pct' => $pcts ? round(array_sum($pcts) / count($pcts), 1) : null,
            'questions_hardest_first' => $questions,
            'students' => $students,
            'note' => 'facility_pct = average score on a question (low = hardest). Grades scaled to the quiz maximum.',
        ];
        if ($truncated) {
            $result['truncated'] = 'Only the first 150 learners are included.';
        }
        return $result;
    }

    /**
     * get_grade_detail: a learner's grades with the teacher feedback on each item and the manual-override
     * flag — the "what feedback did they get" view that get_grades omits.
     *
     * @param array $args Arguments.
     * @param int $userid Acting user.
     * @param array $domains Enabled domains.
     * @return array
     */
    protected static function get_grade_detail(array $args, int $userid, array $domains): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');
        $courseid = (int) ($args['courseid'] ?? 0);
        $gate = self::gate('grades', $domains, $courseid, $userid);
        if (isset($gate['error'])) {
            return $gate;
        }
        $context = $gate['context'];
        $target = (int) ($args['userid'] ?? $userid);
        if ($target === $userid) {
            if (
                !has_capability('moodle/grade:view', $context, $userid)
                    && !has_capability('moodle/grade:viewall', $context, $userid)
            ) {
                return ['error' => 'you may not view grades in this course'];
            }
        } else if (!has_capability('moodle/grade:viewall', $context, $userid)) {
            return ['error' => 'you may not view other learners\' grades in this course'];
        }
        if ($target !== $userid) {
            $restrict = self::group_restricted_userids($courseid, $context, $userid);
            if ($restrict !== null && !in_array($target, $restrict, true)) {
                return ['error' => 'you may not view this learner\'s grades (they are in another group)'];
            }
        }
        $items = self::grade_items($courseid);
        if (!$items) {
            return ['courseid' => $courseid, 'userid' => $target, 'grades' => []];
        }
        // Bulk-load this learner's grades for the visible items in one query (not a fetch per item).
        $byitem = [];
        $visibleids = [];
        foreach ($items as $item) {
            if (!$item->is_hidden()) {
                $visibleids[] = (int) $item->id;
            }
        }
        if ($visibleids) {
            [$insql, $p] = $DB->get_in_or_equal($visibleids, SQL_PARAMS_NAMED);
            $p['userid'] = $target;
            foreach ($DB->get_records_select('grade_grades', "userid = :userid AND itemid $insql", $p) as $r) {
                $byitem[(int) $r->itemid] = new \grade_grade($r, false);
            }
        }
        $grades = [];
        foreach ($items as $item) {
            if ($item->is_hidden()) {
                continue;
            }
            $grade = $byitem[$item->id] ?? null;
            if ($grade) {
                $grade->grade_item = $item;
                if ($grade->is_hidden()) {
                    continue;
                }
            }
            $final = $grade ? $grade->finalgrade : null;
            $display = ($final === null) ? '-' : grade_format_gradevalue($final, $item, true);
            $feedback = '';
            if ($grade && $grade->feedback !== null && (string) $grade->feedback !== '') {
                $feedback = trim(html_to_text((string) $grade->feedback, 0, false));
            }
            $grades[] = [
                'item' => $item->get_name(),
                'grade' => $display,
                'feedback' => $feedback !== '' ? \core_text::substr($feedback, 0, 1500) : null,
                'overridden' => $grade ? (bool) $grade->overridden : false,
            ];
        }
        return ['courseid' => $courseid, 'userid' => $target, 'grades' => $grades,
            'note' => '"overridden" = set manually in the gradebook rather than by the activity.'];
    }

    /**
     * get_gradebook_structure: the category tree with each item's weight and the aggregation method, so
     * the assistant can explain how a course total is composed.
     *
     * @param array $args Arguments.
     * @param int $userid Acting user.
     * @param array $domains Enabled domains.
     * @return array
     */
    protected static function get_gradebook_structure(array $args, int $userid, array $domains): array {
        global $CFG;
        if (!in_array('grades', $domains, true)) {
            return ['error' => 'the grades domain is disabled'];
        }
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/grade/constants.php');
        $courseid = (int) ($args['courseid'] ?? 0);
        $gate = self::gate('grades', $domains, $courseid, $userid, 'moodle/grade:viewall');
        if (isset($gate['error'])) {
            return $gate;
        }
        $aggmap = [
            GRADE_AGGREGATE_MEAN => 'Mean of grades',
            GRADE_AGGREGATE_WEIGHTED_MEAN => 'Weighted mean',
            GRADE_AGGREGATE_WEIGHTED_MEAN2 => 'Simple weighted mean',
            GRADE_AGGREGATE_EXTRACREDIT_MEAN => 'Mean with extra credit',
            GRADE_AGGREGATE_MEDIAN => 'Median',
            GRADE_AGGREGATE_MIN => 'Lowest grade',
            GRADE_AGGREGATE_MAX => 'Highest grade',
            GRADE_AGGREGATE_MODE => 'Mode',
            GRADE_AGGREGATE_SUM => 'Natural (sum of grades)',
        ];
        $items = self::grade_items($courseid);
        if (!$items) {
            return ['courseid' => $courseid, 'note' => 'no grade items in this course'];
        }
        $bycat = [];
        foreach ($items as $item) {
            if ($item->itemtype === 'course' || $item->itemtype === 'category') {
                continue;   // Aggregation items, not leaves.
            }
            $bycat[(int) $item->categoryid][] = $item;
        }
        $categories = [];
        foreach ((\grade_category::fetch_all(['courseid' => $courseid]) ?: []) as $cat) {
            $leaves = [];
            foreach (($bycat[(int) $cat->id] ?? []) as $item) {
                if ($item->is_hidden()) {
                    continue;
                }
                $weight = null;
                if ($item->weightoverride || (float) $item->aggregationcoef2 > 0) {
                    $weight = round((float) $item->aggregationcoef2 * 100, 1);
                } else if ((float) $item->aggregationcoef > 0) {
                    $weight = round((float) $item->aggregationcoef, 3);
                }
                $leaves[] = ['name' => $item->get_name(), 'max' => (float) $item->grademax, 'weight_pct' => $weight];
            }
            $categories[] = [
                'name' => $cat->get_name() ?: get_string('gradebook', 'grades'),
                'aggregation' => $aggmap[(int) $cat->aggregation] ?? ('method ' . (int) $cat->aggregation),
                'items' => $leaves,
            ];
        }
        return ['courseid' => $courseid, 'categories' => $categories,
            'note' => 'weight_pct is each item\'s contribution where defined; aggregation is how a category combines its items.'];
    }

    /**
     * get_forum_activity: discussion/post counts and the most active posters across a course's forums (or
     * one forum). Grouped queries only; honours per-forum view capability and separate groups.
     *
     * @param array $args Arguments.
     * @param int $userid Acting user.
     * @param array $domains Enabled domains.
     * @return array
     */
    protected static function get_forum_activity(array $args, int $userid, array $domains): array {
        global $DB;
        $courseid = (int) ($args['courseid'] ?? 0);
        $gate = self::gate('participants', $domains, $courseid, $userid);
        if (isset($gate['error'])) {
            return $gate;
        }
        $course = $gate['course'];
        $context = $gate['context'];
        $restrict = self::group_restricted_userids($courseid, $context, $userid);
        $modinfo = get_fast_modinfo($course, $userid);
        $cmid = (int) ($args['cmid'] ?? 0);

        $forums = [];
        $names = [];
        foreach ($modinfo->get_instances_of('forum') as $cm) {
            if (!$cm->uservisible || ($cmid && (int) $cm->id !== $cmid)) {
                continue;
            }
            if (!has_capability('mod/forum:viewdiscussion', \context_module::instance($cm->id), $userid)) {
                continue;
            }
            $forums[] = (int) $cm->instance;
            $names[] = $cm->get_formatted_name();
        }
        if (!$forums) {
            return ['courseid' => $courseid, 'note' => 'no forums you can view in this course'];
        }
        [$fin, $fparams] = $DB->get_in_or_equal($forums, SQL_PARAMS_NAMED);

        // Under separate groups (no accessallgroups), confine the aggregate counts to discussions in the
        // caller's own groups (or all-participants), so "posts"/"distinct_posters" never span other groups.
        $allowedgroups = null;
        if (
            (int) groups_get_course_groupmode($course) === SEPARATEGROUPS
                && !has_capability('moodle/site:accessallgroups', $context, $userid)
        ) {
            $allowedgroups = array_map('intval', array_keys(groups_get_all_groups($courseid, $userid, 0, 'g.id')));
        }
        $dwhere = "forum $fin";
        $dparams = $fparams;
        $pwhere = "fd.forum $fin AND fp.userid > 0";
        $pparams = $fparams;
        if ($allowedgroups !== null) {
            if ($allowedgroups) {
                [$gin, $gp] = $DB->get_in_or_equal($allowedgroups, SQL_PARAMS_NAMED, 'grpd');
                $dwhere .= " AND (groupid <= 0 OR groupid $gin)";
                $dparams += $gp;
                [$gin2, $gp2] = $DB->get_in_or_equal($allowedgroups, SQL_PARAMS_NAMED, 'grpp');
                $pwhere .= " AND (fd.groupid <= 0 OR fd.groupid $gin2)";
                $pparams += $gp2;
            } else {
                $dwhere .= " AND groupid <= 0";
                $pwhere .= " AND fd.groupid <= 0";
            }
        }
        $discussions = (int) $DB->count_records_select('forum_discussions', $dwhere, $dparams);
        $rows = $DB->get_records_sql(
            "SELECT fp.userid, COUNT(*) AS posts
               FROM {forum_posts} fp
               JOIN {forum_discussions} fd ON fd.id = fp.discussion
              WHERE $pwhere
           GROUP BY fp.userid
           ORDER BY posts DESC",
            $pparams
        );
        $totalposts = 0;
        foreach ($rows as $r) {
            $totalposts += (int) $r->posts;
        }
        $top = [];
        foreach ($rows as $r) {
            $uid = (int) $r->userid;
            if ($restrict !== null && !in_array($uid, $restrict, true)) {
                continue;
            }
            if (count($top) >= 25) {
                break;
            }
            $top[] = ['id' => $uid, 'posts' => (int) $r->posts];
        }
        $top = self::with_names($top);
        return [
            'courseid' => $courseid,
            'forums' => $names,
            'discussions' => $discussions,
            'posts' => $totalposts,
            'distinct_posters' => count($rows),
            'top_posters' => $top,
            'note' => 'Counts cover the forums you can view; top_posters is capped at 25 (hidden-group members excluded).',
        ];
    }

    /**
     * get_course_info: a course's settings and dates (created, edited, start, end, visibility, format).
     *
     * @param array $args Arguments.
     * @param int $userid Acting user.
     * @param array $domains Enabled domains.
     * @return array
     */
    protected static function get_course_info(array $args, int $userid, array $domains): array {
        $gate = self::gate('courses', $domains, (int) ($args['courseid'] ?? 0), $userid);
        if (isset($gate['error'])) {
            return $gate;
        }
        $course = $gate['course'];
        $summary = trim(html_to_text((string) $course->summary, 0, false));
        return [
            'courseid'  => (int) $course->id,
            'fullname'  => format_string($course->fullname),
            'shortname' => $course->shortname,
            'created'   => (int) $course->timecreated ? date('c', (int) $course->timecreated) : null,
            'modified'  => (int) $course->timemodified ? date('c', (int) $course->timemodified) : null,
            'startdate' => (int) $course->startdate ? date('c', (int) $course->startdate) : null,
            'enddate'   => (int) $course->enddate ? date('c', (int) $course->enddate) : null,
            'visible'   => (bool) $course->visible,
            'format'    => $course->format,
            'summary'   => $summary !== '' ? \core_text::substr($summary, 0, 1000) : null,
        ];
    }

    /**
     * get_enrolment_info: how learners are enrolled (method, dates, active/suspended) and their last
     * course access. Two bulk queries, aggregated per learner, group-aware.
     *
     * @param array $args Arguments.
     * @param int $userid Acting user.
     * @param array $domains Enabled domains.
     * @return array
     */
    protected static function get_enrolment_info(array $args, int $userid, array $domains): array {
        global $DB;
        $gate = self::gate(
            'participants',
            $domains,
            (int) ($args['courseid'] ?? 0),
            $userid,
            'moodle/course:viewparticipants'
        );
        if (isset($gate['error'])) {
            return $gate;
        }
        $courseid = (int) $gate['course']->id;
        $restrict = self::group_restricted_userids($courseid, $gate['context'], $userid);
        $target = (int) ($args['userid'] ?? 0);

        $sql = "SELECT ue.id, ue.userid, ue.status, ue.timestart, ue.timeend, ue.timecreated, e.enrol
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE e.courseid = :cid";
        $params = ['cid' => $courseid];
        if ($target) {
            $sql .= " AND ue.userid = :uid";
            $params['uid'] = $target;
        }
        $rows = $DB->get_records_sql($sql, $params);
        $lastaccess = [];
        if ($target) {
            // Single-learner lookup: read just that row, not the whole cohort's last-access.
            $la = $DB->get_field('user_lastaccess', 'timeaccess', ['courseid' => $courseid, 'userid' => $target]);
            $lastaccess = [$target => (int) $la];
        } else {
            foreach ($DB->get_records('user_lastaccess', ['courseid' => $courseid], '', 'userid, timeaccess') as $la) {
                $lastaccess[(int) $la->userid] = (int) $la->timeaccess;
            }
        }

        $byuser = [];
        foreach ($rows as $r) {
            $uid = (int) $r->userid;
            if ($restrict !== null && !in_array($uid, $restrict, true)) {
                continue;
            }
            if (!isset($byuser[$uid])) {
                $byuser[$uid] = ['methods' => [], 'active' => false, 'enrolledts' => 0,
                    'startts' => 0, 'endts' => 0];
            }
            $byuser[$uid]['methods'][] = $r->enrol;
            if ((int) $r->status === 0) {   // ENROL_USER_ACTIVE.
                $byuser[$uid]['active'] = true;
            }
            $tc = (int) $r->timecreated;
            if ($tc && ($byuser[$uid]['enrolledts'] === 0 || $tc < $byuser[$uid]['enrolledts'])) {
                $byuser[$uid]['enrolledts'] = $tc;
            }
            if ((int) $r->timestart) {
                $byuser[$uid]['startts'] = (int) $r->timestart;
            }
            if ((int) $r->timeend) {
                $byuser[$uid]['endts'] = (int) $r->timeend;
            }
        }
        $out = [];
        foreach ($byuser as $uid => $u) {
            if (count($out) >= 150) {
                break;
            }
            // Day-granularity dates, and omit a date key entirely when it is not set (both cut tokens on
            // the up-to-150-row payload). 'id' is always present so with_names() still resolves names.
            $row = [
                'id'      => $uid,
                'methods' => array_values(array_unique($u['methods'])),
                'status'  => $u['active'] ? 'active' : 'suspended',
            ];
            if ($u['enrolledts']) {
                $row['enrolled_on'] = date('Y-m-d', $u['enrolledts']);
            }
            if ($u['startts']) {
                $row['starts'] = date('Y-m-d', $u['startts']);
            }
            if ($u['endts']) {
                $row['ends'] = date('Y-m-d', $u['endts']);
            }
            if (!empty($lastaccess[$uid])) {
                $row['last_access'] = date('Y-m-d', $lastaccess[$uid]);
            }
            $out[] = $row;
        }
        return ['courseid' => $courseid, 'enrolments' => self::with_names($out),
            'note' => 'status is active or suspended; dates are the calendar day (YYYY-MM-DD) and a missing '
                . 'date field means it is not set; "methods" are the enrolment plugins.'];
    }

    /**
     * get_student_groups: the groups in a course with member counts, or one learner's groups. Uses the
     * high-level groups API so it tracks Moodle changes.
     *
     * @param array $args Arguments.
     * @param int $userid Acting user.
     * @param array $domains Enabled domains.
     * @return array
     */
    protected static function get_student_groups(array $args, int $userid, array $domains): array {
        global $DB;
        $gate = self::gate(
            'participants',
            $domains,
            (int) ($args['courseid'] ?? 0),
            $userid,
            'moodle/course:viewparticipants'
        );
        if (isset($gate['error'])) {
            return $gate;
        }
        $courseid = (int) $gate['course']->id;
        $target = (int) ($args['userid'] ?? 0);

        if ($target) {
            // Under separate groups a caller without accessallgroups must not learn the group membership
            // of a learner outside their own groups — mirror the whole-class branch and the sibling tools.
            $restrict = self::group_restricted_userids($courseid, $gate['context'], $userid);
            if ($restrict !== null && !in_array($target, $restrict, true)) {
                return ['error' => 'you may not view this learner (they are in another group)'];
            }
            $names = [];
            foreach (groups_get_all_groups($courseid, $target, 0, 'g.id, g.name') as $g) {
                $names[] = format_string($g->name);
            }
            return ['courseid' => $courseid, 'userid' => $target, 'groups' => array_values($names)];
        }

        $restrict = self::group_restricted_userids($courseid, $gate['context'], $userid);
        $groups = groups_get_all_groups($courseid, 0, 0, 'g.id, g.name');
        // One grouped COUNT across all groups instead of a membership query per group.
        $counts = [];
        if ($groups) {
            [$gin, $gparams] = $DB->get_in_or_equal(array_map('intval', array_keys($groups)), SQL_PARAMS_NAMED);
            $usersql = '';
            if ($restrict !== null) {
                if (!$restrict) {
                    $usersql = ' AND 1 = 0';   // Separate groups, caller in none: sees no members.
                } else {
                    [$uin, $uparams] = $DB->get_in_or_equal($restrict, SQL_PARAMS_NAMED, 'ru');
                    $usersql = " AND userid $uin";
                    $gparams += $uparams;
                }
            }
            foreach (
                $DB->get_records_sql(
                    "SELECT groupid, COUNT(userid) AS members
                       FROM {groups_members}
                      WHERE groupid $gin$usersql
                   GROUP BY groupid",
                    $gparams
                ) as $r
            ) {
                $counts[(int) $r->groupid] = (int) $r->members;
            }
        }
        $out = [];
        foreach ($groups as $g) {
            if (count($out) >= 100) {
                break;
            }
            $out[] = ['name' => format_string($g->name), 'members' => $counts[(int) $g->id] ?? 0];
        }
        return ['courseid' => $courseid, 'groups' => $out, 'note' => 'member counts honour your group visibility.'];
    }

    /**
     * get_activity_log: engagement from the standard log store — event counts and last activity per
     * learner over a recent window. A no-op note when the standard log store is not in use.
     *
     * @param array $args Arguments.
     * @param int $userid Acting user.
     * @param array $domains Enabled domains.
     * @return array
     */
    protected static function get_activity_log(array $args, int $userid, array $domains): array {
        global $DB;
        $gate = self::gate('progress', $domains, (int) ($args['courseid'] ?? 0), $userid, 'report/log:view');
        if (isset($gate['error'])) {
            return $gate;
        }
        $courseid = (int) $gate['course']->id;
        $context = $gate['context'];

        $hasreader = false;
        foreach (get_log_manager()->get_readers('\core\log\sql_reader') as $reader) {
            $hasreader = true;
            break;
        }
        if (!$hasreader || !$DB->get_manager()->table_exists('logstore_standard_log')) {
            return ['courseid' => $courseid,
                'note' => 'the standard log store is not enabled, so activity logs are unavailable'];
        }
        $days = max(1, min(180, (int) ($args['days'] ?? 30)));
        $since = time() - ($days * DAYSECS);
        $restrict = self::group_restricted_userids($courseid, $context, $userid);
        $target = (int) ($args['userid'] ?? 0);

        $sql = "SELECT userid, COUNT(*) AS events, MAX(timecreated) AS lastseen
                  FROM {logstore_standard_log}
                 WHERE courseid = :cid AND timecreated >= :since AND userid > 0";
        $params = ['cid' => $courseid, 'since' => $since];
        if ($target) {
            $sql .= " AND userid = :uid";
            $params['uid'] = $target;
        }
        $sql .= " GROUP BY userid ORDER BY events DESC";
        $rows = $DB->get_records_sql($sql, $params);
        $out = [];
        foreach ($rows as $r) {
            $uid = (int) $r->userid;
            if ($restrict !== null && !in_array($uid, $restrict, true)) {
                continue;
            }
            if (count($out) >= 150) {
                break;
            }
            $out[] = ['id' => $uid, 'events' => (int) $r->events, 'last_active' => date('Y-m-d', (int) $r->lastseen)];
        }
        return ['courseid' => $courseid, 'window_days' => $days, 'learners' => self::with_names($out),
            'note' => '"events" = logged actions in the window; low or zero suggests disengagement.'];
    }

    /**
     * get_forum_posts: the actual post text in a forum's discussions, with privacy guards (Q&A forums and
     * private replies the caller may not read are withheld). Student-authored content, capped.
     *
     * @param array $args Arguments.
     * @param int $userid Acting user.
     * @param array $domains Enabled domains.
     * @return array
     */
    protected static function get_forum_posts(array $args, int $userid, array $domains): array {
        global $DB;
        $gate = self::gate('participants', $domains, (int) ($args['courseid'] ?? 0), $userid);
        if (isset($gate['error'])) {
            return $gate;
        }
        $courseid = (int) $gate['course']->id;
        $cmid = (int) ($args['cmid'] ?? 0);
        if (!$cmid) {
            return ['error' => 'cmid (the forum) is required'];
        }
        $modinfo = get_fast_modinfo($gate['course'], $userid);
        try {
            $cm = $modinfo->get_cm($cmid);
        } catch (\moodle_exception $e) {
            return ['error' => 'that activity is not part of this course'];
        }
        if ($cm->modname !== 'forum') {
            return ['error' => 'that activity is not a forum'];
        }
        if (!$cm->uservisible) {
            return ['error' => 'you may not view that forum'];
        }
        $context = \context_module::instance($cmid);
        if (!has_capability('mod/forum:viewdiscussion', $context, $userid)) {
            return ['error' => 'you may not read this forum'];
        }
        $forum = $DB->get_record('forum', ['id' => $cm->instance], 'id, type');
        if (
            $forum && $forum->type === 'qanda'
                && !has_capability('mod/forum:viewqandawithoutposting', $context, $userid)
        ) {
            return ['courseid' => $courseid, 'cmid' => $cmid,
                'note' => 'this is a question-and-answer forum; posts are not readable without having posted'];
        }
        $canprivate = has_capability('mod/forum:readprivatereplies', $context, $userid);

        $discussionid = (int) ($args['discussionid'] ?? 0);
        // Under separate groups (without accessallgroups) a caller may only read discussions for their own
        // groups plus all-participants discussions (groupid <= 0) — mirrors forum_user_can_see_group_discussion.
        $allowedgroups = null;
        if (
            groups_get_activity_groupmode($cm, $gate['course']) == SEPARATEGROUPS
                && !has_capability('moodle/site:accessallgroups', $context, $userid)
        ) {
            $allowedgroups = array_map('intval', array_keys(groups_get_all_groups($courseid, $userid, 0, 'g.id')));
        }
        $select = 'forum = :forum';
        $dparams = ['forum' => $cm->instance];
        if ($discussionid) {
            $select .= ' AND id = :id';
            $dparams['id'] = $discussionid;
        }
        if ($allowedgroups !== null) {
            if ($allowedgroups) {
                [$gin, $gp] = $DB->get_in_or_equal($allowedgroups, SQL_PARAMS_NAMED, 'grp');
                $select .= " AND (groupid <= 0 OR groupid $gin)";
                $dparams += $gp;
            } else {
                $select .= ' AND groupid <= 0';
            }
        }
        $discussions = $DB->get_records_select(
            'forum_discussions',
            $select,
            $dparams,
            'timemodified DESC',
            'id, name',
            0,
            $discussionid ? 1 : 5
        );
        // Fetch each discussion's posts, collecting author ids across all of them, then resolve every
        // name in one query (not one lookup per discussion).
        $postsbyd = [];
        $allids = [];
        foreach ($discussions as $d) {
            $posts = $DB->get_records(
                'forum_posts',
                ['discussion' => $d->id],
                'created ASC',
                'id, userid, message, created, privatereplyto',
                0,
                40
            );
            $postsbyd[$d->id] = $posts;
            foreach ($posts as $p) {
                $allids[(int) $p->userid] = true;
            }
        }
        $names = [];
        if ($allids) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($allids), SQL_PARAMS_NAMED);
            foreach (
                $DB->get_records_select(
                    'user',
                    "id $insql",
                    $params,
                    '',
                    'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename'
                ) as $u
            ) {
                $names[(int) $u->id] = fullname($u);
            }
        }
        $out = [];
        foreach ($discussions as $d) {
            $items = [];
            foreach ($postsbyd[$d->id] as $p) {
                if ((int) $p->privatereplyto && !$canprivate) {
                    continue;   // Private reply — only the recipient or privileged staff may read it.
                }
                $text = trim(html_to_text((string) $p->message, 0, false));
                $items[] = [
                    'author' => $names[(int) $p->userid] ?? ('user ' . (int) $p->userid),
                    'posted' => date('c', (int) $p->created),
                    'text'   => \core_text::substr($text, 0, 600),
                ];
            }
            $out[] = ['discussion' => format_string($d->name), 'posts' => $items];
        }
        return ['courseid' => $courseid, 'cmid' => $cmid, 'discussions' => $out,
            'note' => 'Student-authored; summarise rather than quote. Up to 5 discussions, 40 posts each; '
                . 'private replies excluded unless you may read them.'];
    }

    /**
     * get_grading_queue: assignments with submitted work not yet graded, across the caller's courses.
     * A per-assignment count via one query each, capped to 30 courses so it stays cheap.
     *
     * @param array $args Arguments.
     * @param int $userid Acting user.
     * @param array $domains Enabled domains.
     * @return array
     */
    protected static function get_grading_queue(array $args, int $userid, array $domains): array {
        global $DB;
        if (!in_array('grades', $domains, true)) {
            return ['error' => 'the grades data is not enabled'];
        }
        $out = [];
        $scanned = 0;
        // Confine the queue to the caller's granted course scope (a SCOPE_COURSE grant must narrow it too,
        // not just their Moodle enrolments); the per-assignment mod/assign:grade check still applies below.
        $eff = access_manager::effective_course_access($userid);
        foreach (enrol_get_all_users_courses($userid, true, 'id, fullname') as $course) {
            if (
                !$eff['all'] && !$eff['enrolled']
                    && !in_array((int) $course->id, $eff['courseids'], true)
            ) {
                continue;
            }
            if ($scanned >= 30) {
                break;
            }
            $scanned++;
            $modinfo = get_fast_modinfo($course->id, $userid);
            // Collect the gradeable, visible assignments first (keeping the per-module capability gate),
            // then one grouped COUNT for the whole course instead of a query per assignment.
            $gradeable = [];
            foreach ($modinfo->get_instances_of('assign') as $cm) {
                if (!$cm->uservisible) {
                    continue;
                }
                if (!has_capability('mod/assign:grade', \context_module::instance($cm->id), $userid)) {
                    continue;
                }
                $gradeable[] = $cm;
            }
            $items = [];
            if ($gradeable) {
                // Under separate groups, count only submissions by learners the caller may see.
                $restrict = self::group_restricted_userids(
                    (int) $course->id,
                    \context_course::instance((int) $course->id),
                    $userid
                );
                if (is_array($restrict) && !$restrict) {
                    continue;   // Caller in no group under separate groups: nothing visible to grade here.
                }
                $instanceids = array_map(static fn($cm) => (int) $cm->instance, $gradeable);
                [$insql, $params] = $DB->get_in_or_equal($instanceids, SQL_PARAMS_NAMED);
                $usersql = '';
                if (is_array($restrict)) {
                    [$uinsql, $uparams] = $DB->get_in_or_equal($restrict, SQL_PARAMS_NAMED, 'gu');
                    $usersql = " AND s.userid $uinsql";
                    $params += $uparams;
                }
                $counts = $DB->get_records_sql(
                    "SELECT s.assignment, COUNT(1) c
                       FROM {assign_submission} s
                      WHERE s.assignment $insql AND s.latest = 1 AND s.status = 'submitted'$usersql
                        AND NOT EXISTS (
                            SELECT 1 FROM {assign_grades} g
                             WHERE g.assignment = s.assignment AND g.userid = s.userid
                               AND g.grade >= 0 AND g.timemodified >= s.timemodified)
                   GROUP BY s.assignment",
                    $params
                );
                foreach ($gradeable as $cm) {
                    $need = (int) ($counts[$cm->instance]->c ?? 0);
                    if ($need > 0) {
                        $items[] = ['activity' => $cm->get_formatted_name(), 'cmid' => (int) $cm->id, 'to_grade' => $need];
                    }
                }
            }
            if ($items) {
                $out[] = ['courseid' => (int) $course->id, 'course' => format_string($course->fullname),
                    'assignments' => $items];
            }
        }
        return ['queue' => $out,
            'note' => '"to_grade" = submitted assignment work not yet graded, across up to 30 of your '
                . 'courses; use get_action_link to open a grading screen.'];
    }

    /**
     * get_announcements: the latest posts in the course's announcements (news) forum, with their text.
     *
     * @param array $args Arguments.
     * @param int $userid Acting user.
     * @param array $domains Enabled domains.
     * @return array
     */
    protected static function get_announcements(array $args, int $userid, array $domains): array {
        global $DB;
        $gate = self::gate('courses', $domains, (int) ($args['courseid'] ?? 0), $userid);
        if (isset($gate['error'])) {
            return $gate;
        }
        $courseid = (int) $gate['course']->id;
        $news = $DB->get_record('forum', ['course' => $courseid, 'type' => 'news'], 'id', IGNORE_MULTIPLE);
        if (!$news) {
            return ['courseid' => $courseid, 'announcements' => [], 'note' => 'this course has no announcements forum'];
        }
        // Fail closed: require a resolvable course-module AND the view capability before reading posts.
        $cm = get_coursemodule_from_instance('forum', $news->id, $courseid, false, IGNORE_MISSING);
        if (!$cm || !has_capability('mod/forum:viewdiscussion', \context_module::instance($cm->id), $userid)) {
            return ['courseid' => $courseid, 'note' => 'you may not view announcements in this course'];
        }
        $discussions = $DB->get_records(
            'forum_discussions',
            ['forum' => $news->id],
            'timemodified DESC',
            'id, name, firstpost',
            0,
            10
        );
        // Fetch the first-post bodies in one query rather than one per discussion.
        $posts = [];
        $firstpostids = array_filter(array_map(static fn($d) => (int) $d->firstpost, $discussions));
        if ($firstpostids) {
            $posts = $DB->get_records_list('forum_posts', 'id', $firstpostids, '', 'id, message, created');
        }
        $out = [];
        foreach ($discussions as $d) {
            $post = $posts[$d->firstpost] ?? null;
            $text = $post ? trim(html_to_text((string) $post->message, 0, false)) : '';
            $out[] = [
                'title'  => format_string($d->name),
                'posted' => ($post && $post->created) ? date('c', (int) $post->created) : null,
                'text'   => \core_text::substr($text, 0, 800),
            ];
        }
        return ['courseid' => $courseid, 'announcements' => $out];
    }

    /**
     * get_student_overview: one rounded picture of a single learner — grade, completion, last access,
     * groups, outstanding assignments and an at-risk flag. A consolidation of the finer-grained tools so
     * "how is this learner doing?" takes one call. Grade/completion are null when not visible/enabled.
     *
     * @param array $args Arguments.
     * @param int $userid Acting user.
     * @param array $domains Enabled domains.
     * @return array
     */
    protected static function get_student_overview(array $args, int $userid, array $domains): array {
        global $CFG, $DB;
        $gate = self::gate(
            'participants',
            $domains,
            (int) ($args['courseid'] ?? 0),
            $userid,
            'moodle/course:viewparticipants'
        );
        if (isset($gate['error'])) {
            return $gate;
        }
        $course = $gate['course'];
        $courseid = (int) $course->id;
        $context = $gate['context'];
        $target = (int) ($args['userid'] ?? 0);
        if (!$target) {
            return ['error' => 'userid is required'];
        }
        $restrict = self::group_restricted_userids($courseid, $context, $userid);
        if ($restrict !== null && !in_array($target, $restrict, true)) {
            return ['error' => 'you may not view this learner (they are in another group)'];
        }
        if (!is_enrolled($context, $target)) {
            return ['error' => 'that learner is not enrolled in this course'];
        }
        $user = \core_user::get_user(
            $target,
            'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename'
        );
        $out = ['courseid' => $courseid, 'userid' => $target,
            'name' => $user ? fullname($user) : ('user ' . $target)];

        $la = (int) $DB->get_field(
            'user_lastaccess',
            'timeaccess',
            ['courseid' => $courseid, 'userid' => $target]
        );
        $out['last_access'] = $la ? date('c', $la) : null;

        $groups = [];
        foreach (groups_get_all_groups($courseid, $target, 0, 'g.id, g.name') as $g) {
            $groups[] = format_string($g->name);
        }
        $out['groups'] = $groups;

        require_once($CFG->libdir . '/gradelib.php');
        $overall = null;
        if (has_capability('moodle/grade:viewall', $context, $userid)) {
            foreach ((\grade_item::fetch_all(['courseid' => $courseid, 'itemtype' => 'course']) ?: []) as $ci) {
                $g = \grade_grade::fetch(['itemid' => $ci->id, 'userid' => $target]);
                if ($g && $g->finalgrade !== null && (float) $ci->grademax > 0) {
                    $overall = round((float) $g->finalgrade / (float) $ci->grademax * 100, 1);
                }
                break;
            }
        }
        $out['overall_pct'] = $overall;

        require_once($CFG->libdir . '/completionlib.php');
        // Completion % is the same data get_completion_overview guards on report/completion:view, so
        // re-check it here (this consolidation tool only gates entry on course:viewparticipants).
        $comp = null;
        if (has_capability('report/completion:view', $context, $userid)) {
            $completion = new \completion_info($course);
            if ($completion->is_enabled()) {
                $acts = $completion->get_activities();   // Tracked cm_info, keyed by cmid.
                $tracked = count($acts);
                $done = 0;
                if ($tracked) {
                    // One query for the target's stored completion states: get_data() is never cached for
                    // a user other than the acting user, so a per-activity loop would re-query each cm.
                    $states = $DB->get_records_sql(
                        "SELECT cmc.coursemoduleid, cmc.completionstate
                           FROM {course_modules_completion} cmc
                           JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                          WHERE cm.course = :courseid AND cmc.userid = :userid",
                        ['courseid' => $courseid, 'userid' => $target]
                    );
                    foreach ($acts as $cm) {
                        $row = $states[$cm->id] ?? null;
                        if (
                            $row && in_array(
                                (int) $row->completionstate,
                                [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS],
                                true
                            )
                        ) {
                            $done++;
                        }
                    }
                }
                $comp = $tracked ? (int) round($done / $tracked * 100) : null;
            }
        }
        $out['completion_pct'] = $comp;

        // Outstanding work is the same data get_assignment_status guards on mod/assign:grade, so only
        // count assignments the caller may actually grade (null when they can grade none), in one query.
        $modinfo = get_fast_modinfo($course, $userid);
        $gradeableids = [];
        foreach ($modinfo->get_instances_of('assign') as $cm) {
            if (!$cm->uservisible) {
                continue;
            }
            if (!has_capability('mod/assign:grade', \context_module::instance($cm->id), $userid)) {
                continue;
            }
            $gradeableids[] = (int) $cm->instance;
        }
        $outstanding = null;
        if ($gradeableids) {
            [$insql, $p] = $DB->get_in_or_equal($gradeableids, SQL_PARAMS_NAMED);
            $p['uid'] = $target;
            $submitted = $DB->get_fieldset_select(
                'assign_submission',
                'assignment',
                "userid = :uid AND status = 'submitted' AND latest = 1 AND assignment $insql",
                $p
            );
            $outstanding = count($gradeableids) - count(array_unique($submitted));
        }
        $out['assignments_outstanding'] = $outstanding;

        $stale = ($la === 0) || ((time() - $la) > 14 * DAYSECS);
        $out['at_risk'] = ($overall !== null && $overall < 50) || $stale;
        $out['note'] = '"at_risk" = overall below 50% or no course access in 14+ days. Grade/completion '
            . 'are null when not enabled or not visible to you.';
        return $out;
    }

    /**
     * get_upcoming: deadlines and calendar events in a course over the next window, soonest first.
     *
     * @param array $args Arguments.
     * @param int $userid Acting user.
     * @param array $domains Enabled domains.
     * @return array
     */
    protected static function get_upcoming(array $args, int $userid, array $domains): array {
        global $DB;
        $gate = self::gate('dates', $domains, (int) ($args['courseid'] ?? 0), $userid);
        if (isset($gate['error'])) {
            return $gate;
        }
        $courseid = (int) $gate['course']->id;
        $days = max(1, min(180, (int) ($args['days'] ?? 30)));
        $now = time();
        // Calendar group events are keyed by groupid, so under separate groups (without accessallgroups)
        // show only course-wide events (groupid = 0) plus events for the caller's own groups — as core's
        // calendar does. group_restricted_userids() is user-id based and is deliberately NOT used here.
        $where = "courseid = :cid AND visible = 1 AND timestart >= :now AND timestart <= :until";
        $params = ['cid' => $courseid, 'now' => $now, 'until' => $now + ($days * DAYSECS)];
        if (
            (int) groups_get_course_groupmode($gate['course']) === SEPARATEGROUPS
                && !has_capability('moodle/site:accessallgroups', $gate['context'], $userid)
        ) {
            $mygroups = array_keys(groups_get_all_groups($courseid, $userid, 0, 'g.id'));
            if ($mygroups) {
                [$ingroups, $gp] = $DB->get_in_or_equal($mygroups, SQL_PARAMS_NAMED, 'grp');
                $where .= " AND (groupid = 0 OR groupid $ingroups)";
                $params += $gp;
            } else {
                $where .= " AND groupid = 0";
            }
        }
        $events = $DB->get_records_select(
            'event',
            $where,
            $params,
            'timestart ASC',
            'id, name, timestart, eventtype, modulename',
            0,
            50
        );
        $out = [];
        foreach ($events as $e) {
            $out[] = [
                'name' => format_string($e->name),
                'when' => date('c', (int) $e->timestart),
                'type' => $e->modulename ?: $e->eventtype,
            ];
        }
        return ['courseid' => $courseid, 'window_days' => $days, 'upcoming' => $out,
            'note' => 'Course deadlines and events in the window, soonest first.'];
    }

    /**
     * get_activity_settings: the open/due/close dates for one activity, or a compact list of every dated
     * activity in the course. Uses the generic activity-dates API so it works for any module type, and
     * returns only activities that actually carry dates to keep the payload small.
     *
     * @param array $args Arguments.
     * @param int $userid Acting user.
     * @param array $domains Enabled domains.
     * @return array
     */
    protected static function get_activity_settings(array $args, int $userid, array $domains): array {
        $gate = self::gate('dates', $domains, (int) ($args['courseid'] ?? 0), $userid);
        if (isset($gate['error'])) {
            return $gate;
        }
        $course = $gate['course'];
        $courseid = (int) $course->id;
        $modinfo = get_fast_modinfo($course, $userid);

        $datesof = static function (\cm_info $cm) use ($userid): array {
            $out = [];
            foreach (\core\activity_dates::get_dates_for_module($cm, $userid) as $date) {
                if (empty($date['timestamp'])) {
                    continue;
                }
                $out[] = ['label' => trim((string) ($date['label'] ?? ''), ": \t"), 'when' => date('c', (int) $date['timestamp'])];
            }
            return $out;
        };

        $cmid = (int) ($args['cmid'] ?? 0);
        if ($cmid) {
            try {
                $cm = $modinfo->get_cm($cmid);
            } catch (\moodle_exception $e) {
                return ['error' => 'that activity is not part of this course'];
            }
            if (!$cm->uservisible) {
                return ['error' => 'you may not view that activity'];
            }
            $restrictiontext = trim(html_to_text((string) $cm->availableinfo, 0, false));
            return [
                'courseid'         => $courseid,
                'cmid'             => $cmid,
                'name'             => $cm->get_formatted_name(),
                'type'             => $cm->modname,
                'dates'            => $datesof($cm),
                'restricted'       => !empty($cm->availability),
                'restriction_note' => $restrictiontext !== '' ? $restrictiontext : null,
            ];
        }

        $activities = [];
        foreach ($modinfo->get_cms() as $cm) {
            if (!$cm->uservisible) {
                continue;
            }
            $dates = $datesof($cm);
            if (!$dates) {
                continue;   // Only list activities that actually have dates — keeps the payload lean.
            }
            if (count($activities) >= 100) {
                break;
            }
            $activities[] = [
                'cmid'  => (int) $cm->id,
                'name'  => $cm->get_formatted_name(),
                'type'  => $cm->modname,
                'dates' => $dates,
            ];
        }
        return ['courseid' => $courseid, 'activities' => $activities,
            'note' => 'Only activities with set dates are listed; times are ISO-8601.'];
    }

    /**
     * get_completion_overview: whole-class completion progress plus last course access, flagging stalled
     * learners. Two bulk queries only (progress for all users, then last-access), aggregated server-side
     * into a compact per-learner summary — no per-user round trips.
     *
     * @param array $args Arguments.
     * @param int $userid Acting user.
     * @param array $domains Enabled domains.
     * @return array
     */
    protected static function get_completion_overview(array $args, int $userid, array $domains): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');
        $gate = self::gate('progress', $domains, (int) ($args['courseid'] ?? 0), $userid, 'report/completion:view');
        if (isset($gate['error'])) {
            return $gate;
        }
        $courseid = (int) $gate['course']->id;
        $context = $gate['context'];
        $course = $gate['course'];
        $completion = new \completion_info($course);
        if (!$completion->is_enabled()) {
            return ['courseid' => $courseid, 'note' => 'completion tracking is not enabled in this course'];
        }
        $tracked = count($completion->get_activities());
        if (!$tracked) {
            return ['courseid' => $courseid, 'note' => 'no activities have completion tracking turned on'];
        }

        $restrict = self::group_restricted_userids($courseid, $context, $userid);
        $progressall = $completion->get_progress_all('', [], 0, '', 200, 0, $context);
        $lastaccess = [];
        foreach ($DB->get_records('user_lastaccess', ['courseid' => $courseid], '', 'userid, timeaccess') as $row) {
            $lastaccess[(int) $row->userid] = (int) $row->timeaccess;
        }

        $now = time();
        $cutoff = 14 * DAYSECS;
        $students = [];
        $stalled = 0;
        $truncated = false;
        foreach ($progressall as $uid => $record) {
            $uid = (int) $uid;
            if ($restrict !== null && !in_array($uid, $restrict, true)) {
                continue;
            }
            if (count($students) >= 150) {
                $truncated = true;
                break;
            }
            $done = 0;
            foreach ($record->progress as $cmpl) {
                if (in_array((int) $cmpl->completionstate, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS], true)) {
                    $done++;
                }
            }
            $last = $lastaccess[$uid] ?? 0;
            $isstalled = ($last === 0) || (($now - $last) > $cutoff);
            if ($isstalled) {
                $stalled++;
            }
            $row = [
                'id'        => $uid,
                'name'      => fullname($record),
                'completed' => $done,
                'of'        => $tracked,
                'percent'   => $tracked ? (int) round($done / $tracked * 100) : 0,
                'stalled'   => $isstalled,
            ];
            if ($last) {
                $row['last_access'] = date('Y-m-d', $last);   // Day granularity; omitted when never accessed.
            }
            $students[] = $row;
        }
        if (!$students) {
            return ['courseid' => $courseid, 'note' => 'no learners in scope'];
        }

        $result = [
            'courseid'            => $courseid,
            'tracked_activities'  => $tracked,
            'student_count'       => count($students),
            'stalled_count'       => $stalled,
            'students'            => $students,
            'note'               => '"percent" is completion-tracked activities finished. "stalled" = no '
                . 'course access in 14+ days (or never). Dates are the calendar day (YYYY-MM-DD); a '
                . 'missing last_access means never accessed.',
        ];
        if ($truncated) {
            $result['truncated'] = 'Only the first 150 learners are included.';
        }
        return $result;
    }

    /**
     * Return a direct link + short steps for a change the user wants to make — but only when the user
     * already holds the relevant edit capability. This tool makes NO change: it checks the caller's own
     * capability in the item's own context and returns a URL built here (never model-authored) plus
     * fixed steps, or allowed=false. The read-only wall is unaffected.
     *
     * @param array $args {intent, courseid, userid?, itemid?, cmid?}.
     * @param int $userid Acting user (the capability is checked as this user).
     * @param array $domains Enabled domains.
     * @return array
     */
    protected static function get_action_link(array $args, int $userid, array $domains): array {
        if (!in_array('actions', $domains, true)) {
            return ['error' => 'the actions domain is disabled'];
        }
        $intent = (string) ($args['intent'] ?? '');
        $courseid = (int) ($args['courseid'] ?? 0);
        if (!$courseid) {
            return ['error' => 'courseid is required'];
        }
        try {
            $course = self::course($courseid);
        } catch (\moodle_exception $e) {
            return ['error' => 'no such course'];
        }
        if (!can_access_course($course, $userid)) {
            return ['error' => 'you may not view this course'];
        }
        $coursecontext = \context_course::instance($courseid);
        // A link is still an admin-scoped action: a SCOPE_COURSE grant must not hand out links for
        // courses outside it, even where the caller holds the edit capability by inheritance.
        if ($blocked = self::scope_blocks($coursecontext, $userid)) {
            return $blocked;
        }
        $targetuser = (int) ($args['userid'] ?? 0);
        $itemid = (int) ($args['itemid'] ?? 0);
        $cmid = (int) ($args['cmid'] ?? 0);
        $denied = ['allowed' => false,
            'reason' => 'The user does not have permission to make that change in this course.'];

        switch ($intent) {
            case 'edit_grade':
            case 'edit_grade_item':
                if (
                    !has_capability('gradereport/singleview:view', $coursecontext, $userid)
                        || !has_capability('moodle/grade:viewall', $coursecontext, $userid)
                        || !has_capability('moodle/grade:edit', $coursecontext, $userid)
                ) {
                    return $denied;
                }
                if ($intent === 'edit_grade') {
                    if (!$targetuser) {
                        return ['error' => 'userid is required for edit_grade'];
                    }
                    $url = new \moodle_url(
                        '/grade/report/singleview/index.php',
                        ['id' => $courseid, 'item' => 'user', 'userid' => $targetuser]
                    );
                    $steps = [
                        'Open the link — it is this learner\'s Single view in the gradebook.',
                        'Find the grade item, tick Override if needed, and type the new grade.',
                        'Click Save at the bottom of the page.',
                    ];
                } else {
                    if (!$itemid) {
                        return ['error' => 'itemid is required for edit_grade_item'];
                    }
                    $url = new \moodle_url(
                        '/grade/report/singleview/index.php',
                        ['id' => $courseid, 'item' => 'grade', 'itemid' => $itemid]
                    );
                    $steps = [
                        'Open the link — it is this grade item\'s Single view across learners.',
                        'Tick Override where needed and type the new grades.',
                        'Click Save at the bottom of the page.',
                    ];
                }
                break;
            case 'view_grader':
                if (
                    !has_capability('gradereport/grader:view', $coursecontext, $userid)
                        || !has_capability('moodle/grade:viewall', $coursecontext, $userid)
                ) {
                    return $denied;
                }
                $url = new \moodle_url('/grade/report/grader/index.php', ['id' => $courseid]);
                $steps = ['Open the link to the full Grader report for the course.'];
                break;
            case 'view_profile':
                if (!$targetuser) {
                    return ['error' => 'userid is required for view_profile'];
                }
                if (!has_capability('moodle/user:viewdetails', $coursecontext, $userid)) {
                    return $denied;
                }
                $url = new \moodle_url('/user/view.php', ['id' => $targetuser, 'course' => $courseid]);
                $steps = ['Open the link to this learner\'s profile in the course.'];
                break;
            case 'edit_activity':
                if (!$cmid) {
                    return ['error' => 'cmid is required for edit_activity'];
                }
                $cm = get_coursemodule_from_id('', $cmid, $courseid, false, IGNORE_MISSING);
                if (!$cm) {
                    return ['error' => 'that activity is not part of this course'];
                }
                $modcontext = \context_module::instance($cm->id);
                if (!has_capability('moodle/course:manageactivities', $modcontext, $userid)) {
                    return $denied;
                }
                $url = new \moodle_url('/course/modedit.php', ['update' => $cmid]);
                $steps = [
                    'Open the link — it is this activity\'s settings page.',
                    'Change the settings you need, then click Save and return to course.',
                ];
                break;
            case 'view_participants':
                if (!has_capability('moodle/course:viewparticipants', $coursecontext, $userid)) {
                    return $denied;
                }
                $url = new \moodle_url('/user/index.php', ['id' => $courseid]);
                $steps = ['Open the link to the course Participants page to manage enrolments.'];
                break;
            default:
                return ['error' => 'unknown intent'];
        }

        return [
            'allowed' => true,
            'url'     => $url->out(false),
            'steps'   => $steps,
            'note'    => 'You cannot make this change yourself. Give the user this link and these steps; they '
                . 'must make the change in Moodle. Do not say or imply you made it.',
        ];
    }
}
