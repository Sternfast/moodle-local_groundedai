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
 * Message panel: renders a conversation's messages, with an optimistic pending turn while sending.
 *
 * @module     local_groundedai/components/panel
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {BaseComponent} from 'core/reactive';
import Templates from 'core/templates';
import {getStrings} from 'core/str';
import Notification from 'core/notification';
import {notifyFilterContentUpdated} from 'core_filters/events';
import * as Repository from 'local_groundedai/repository';

export default class extends BaseComponent {

    /**
     * Component descriptor.
     */
    create() {
        this.name = 'local_groundedai_panel';
        this._oldestId = 0;
        this._newestId = 0;
        this._refreshing = false;
        this._announce = false;
        this._loadingOlder = false;
        this._typer = null;
        this._typerDone = null;
        this._typerTimeout = null;
    }

    /**
     * Initial render. Load-older is delegated on the persistent panel element (bound once).
     */
    async stateReady() {
        this.addEventListener(this.element, 'click', (e) => {
            if (e.target.closest('[data-action="gai-loadmore"]')) {
                e.preventDefault();
                this._loadOlder();
            }
        });
        // When this tab/view is brought back to the foreground, pull in any turns another open view of the
        // same conversation added while we were hidden (same-chat-in-two-places consistency).
        this.addEventListener(document, 'visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                this._refreshNew();
            }
        });
        await this._render();
    }

    /**
     * Watchers.
     *
     * @returns {Array}
     */
    getWatchers() {
        return [
            {watch: 'config.currentConversationId:updated', handler: this._onConversationChanged},
            {watch: 'config.messagesdirty:updated', handler: this._onMessagesChanged},
            {watch: 'config.sending:updated', handler: this._onSending},
            {watch: 'config.replyseq:updated', handler: this._onReplyArrived},
            {watch: 'config.senderror:updated', handler: this._onSendError},
            {watch: 'config.cancelseq:updated', handler: this._onCancelled},
        ];
    }

    /**
     * A different conversation was opened: render without announcing.
     */
    _onConversationChanged() {
        this._cancelTyper();
        this._announce = false;
        this._render();
    }

    /**
     * Messages changed (a reply arrived): render and announce the new reply to assistive tech.
     */
    _onMessagesChanged() {
        this._announce = true;
        this._render();
    }

    /**
     * Append any turns added to the open conversation by another view since this one last rendered. Uses
     * the get_messages "after" cursor; silent on failure (a background refresh must not nag). Skipped while
     * a send is in flight here, since that reply arrives through _onReplyArrived.
     */
    async _refreshNew() {
        const id = parseInt(this.reactive.state.config.currentConversationId, 10);
        if (!id || !this._newestId || this._refreshing || this.reactive.state.config.sending) {
            return;
        }
        this._refreshing = true;
        let page;
        try {
            page = await Repository.getMessages(id, 0, 50, this._newestId);
        } catch (error) {
            this._refreshing = false;
            return;
        }
        this._refreshing = false;
        const fresh = (page.messages || []).filter((m) => parseInt(m.id, 10) > this._newestId);
        if (!fresh.length) {
            return;
        }
        const [you, assistant] = await getStrings([
            {key: 'youlabel', component: 'local_groundedai'},
            {key: 'assistantlabel', component: 'local_groundedai'},
        ]);
        for (const m of fresh) {
            await this._appendStatic(this._shape(m, you, assistant));
        }
        this._newestId = parseInt(fresh[fresh.length - 1].id, 10);
        this._scrollToBottom();
    }

    /**
     * Render the open conversation's messages.
     */
    async _render() {
        this._cancelTyper();
        const id = parseInt(this.reactive.state.config.currentConversationId, 10);
        if (!id) {
            const empty = await Templates.renderForPromise('local_groundedai/panel_content', {empty: true});
            Templates.replaceNodeContents(this.element, empty.html, empty.js);
            this._oldestId = 0;
            return;
        }
        let page;
        try {
            page = await Repository.getMessages(id, 0, 50);
        } catch (error) {
            Notification.exception(error);
            return;
        }
        const [you, assistant] = await getStrings([
            {key: 'youlabel', component: 'local_groundedai'},
            {key: 'assistantlabel', component: 'local_groundedai'},
        ]);
        const messages = page.messages.map((m) => this._shape(m, you, assistant));
        const {html, js} = await Templates.renderForPromise('local_groundedai/panel_content',
            {messages, empty: messages.length === 0, hasmore: page.hasmore});
        Templates.replaceNodeContents(this.element, html, js);
        this._applyFilters(this.element);
        this._oldestId = messages.length ? parseInt(page.messages[0].id, 10) : 0;
        this._newestId = messages.length ? parseInt(page.messages[page.messages.length - 1].id, 10) : 0;
        this._scrollToBottom();
        if (this._announce) {
            this._announce = false;
            this._announceLatest(page.messages);
        }
    }

    /**
     * Prepend the previous page of messages when "Earlier messages" is activated.
     */
    async _loadOlder() {
        const id = parseInt(this.reactive.state.config.currentConversationId, 10);
        if (!id || !this._oldestId || this._loadingOlder) {
            return;
        }
        this._loadingOlder = true;
        const prevHeight = this.element.scrollHeight;
        const prevTop = this.element.scrollTop;
        let page;
        try {
            page = await Repository.getMessages(id, this._oldestId, 50);
        } catch (error) {
            this._loadingOlder = false;
            Notification.exception(error);
            return;
        }
        const [you, assistant] = await getStrings([
            {key: 'youlabel', component: 'local_groundedai'},
            {key: 'assistantlabel', component: 'local_groundedai'},
        ]);
        const messages = page.messages.map((m) => this._shape(m, you, assistant));
        const {html, js} = await Templates.renderForPromise('local_groundedai/panel_content',
            {messages, empty: false, hasmore: false});
        const loadmore = this.element.querySelector('[data-action="gai-loadmore"]');
        const holder = document.createElement('div');
        holder.innerHTML = html;
        const ref = loadmore ? loadmore.nextSibling : this.element.firstChild;
        while (holder.firstChild) {
            this.element.insertBefore(holder.firstChild, ref);
        }
        if (js) {
            Templates.runTemplateJS(js);
        }
        if (messages.length) {
            this._oldestId = parseInt(page.messages[0].id, 10);
        }
        if (!page.hasmore && loadmore) {
            loadmore.remove();
        }
        // Keep the reading position: the message that was at the top stays under the viewport.
        this.element.scrollTop = prevTop + (this.element.scrollHeight - prevHeight);
        this._loadingOlder = false;
    }

    /**
     * Announce the newest assistant reply through the app's visually-hidden live region.
     *
     * @param {Array} messages The rendered page of messages.
     */
    _announceLatest(messages) {
        const app = this.element.closest('.gai-app');
        const region = app ? app.querySelector('[data-region="gai-announce"]') : null;
        if (!region) {
            return;
        }
        for (let i = messages.length - 1; i >= 0; i--) {
            if (messages[i].role === 'assistant') {
                region.textContent = (messages[i].content || '').slice(0, 400);
                return;
            }
        }
    }

    /**
     * Shape a message row for the template.
     *
     * @param {Object} m The stored message.
     * @param {String} you The learner's role label.
     * @param {String} assistant The assistant's role label.
     * @returns {Object}
     */
    _shape(m, you, assistant) {
        const isuser = m.role === 'user';
        let sources = [];
        let actions = [];
        if (m.metadata) {
            try {
                const meta = JSON.parse(m.metadata);
                sources = (meta.sources || [])
                    .map((s) => ({n: s.n, name: s.name, url: s.url}))
                    // Only ever render an http(s) source link, so a non-http scheme (javascript:, data:)
                    // can never reach the href if a 'sources' key is populated in future.
                    .filter((s) => s.url && /^https?:\/\//i.test(String(s.url)));
                actions = this._actionLabels(meta.toolsused || []);
            } catch (error) {
                sources = [];
                actions = [];
            }
        }
        return {
            role: isuser ? 'user' : 'assistant',
            rolelabel: isuser ? you : assistant,
            content: m.content,
            contenthtml: m.contenthtml || '',
            hashtml: Boolean(m.contenthtml),
            sources,
            hassources: sources.length > 0,
            actions,
            hasactions: actions.length > 0,
        };
    }

    /**
     * Turn the raw tool names the assistant used into a short, de-duplicated, human list.
     *
     * @param {Array} tools Tool names from the message metadata.
     * @returns {Array} [{label}]
     */
    _actionLabels(tools) {
        const map = {
            find: 'Looked up courses & people',
            get_grades: 'Checked the gradebook',
            get_grade_overview: 'Analysed the class gradebook',
            get_course_contents: 'Checked the course outline',
            read_material: 'Read course material',
            get_quiz_questions: 'Read the quiz questions',
            get_activity_settings: 'Checked activity dates',
            get_completion_overview: 'Checked class progress',
            get_assignment_status: 'Checked assignment submissions',
            get_quiz_attempt_analytics: 'Analysed quiz attempts',
            get_grade_detail: 'Checked grades and feedback',
            get_gradebook_structure: 'Checked the gradebook setup',
            get_forum_activity: 'Checked forum activity',
            get_course_info: 'Checked course settings',
            get_enrolment_info: 'Checked enrolments',
            get_student_groups: 'Checked groups',
            get_activity_log: 'Checked activity logs',
            get_student_overview: 'Reviewed a student overview',
            get_announcements: 'Read announcements',
            get_upcoming: 'Checked upcoming deadlines',
            get_forum_posts: 'Read forum posts',
            get_grading_queue: 'Checked the grading queue',
            list_attachments: 'Checked your files',
            read_attachment: 'Read your files',
            export_table: 'Created a file to download',
            export_document: 'Created a file to download',
        };
        const seen = new Set();
        const out = [];
        tools.forEach((name) => {
            const label = map[name];
            if (label && !seen.has(label)) {
                seen.add(label);
                out.push({label});
            }
        });
        return out;
    }

    /**
     * When sending starts, append an optimistic user turn + a typing indicator.
     *
     * @param {Object} args The watcher payload.
     * @param {Object} args.element The changed config element.
     */
    async _onSending({element}) {
        if (!element.sending) {
            // The turn is done: drop only the typing indicator. The question bubble stays (it becomes
            // the real turn on success); the error path removes it separately via _onSendError.
            this.element.querySelectorAll('.gai-typing').forEach((node) => node.remove());
            return;
        }
        // A new question: clear the empty-state prompt, then show the question immediately + a typing cue.
        this.element.querySelectorAll('.gai-empty').forEach((node) => node.remove());
        const [you, thinking] = await getStrings([
            {key: 'youlabel', component: 'local_groundedai'},
            {key: 'thinking', component: 'local_groundedai'},
        ]);
        // These awaits (string + template fetch, uncached on the first send) can outlast a fast reply.
        // If the turn already finished while we waited, bail — otherwise this optimistic row would land
        // AFTER the answer, stranding a typing indicator below it. _onReplyArrived renders the turn then.
        if (!this.reactive.state.config.sending) {
            return;
        }
        const {html} = await Templates.renderForPromise('local_groundedai/panel_pending',
            {text: this.reactive.state.config.pendinguser, youlabel: you, thinking});
        if (!this.reactive.state.config.sending) {
            return;
        }
        const holder = document.createElement('div');
        holder.innerHTML = html;
        while (holder.firstChild) {
            this.element.appendChild(holder.firstChild);
        }
        this._scrollToBottom();
    }

    /**
     * The reply arrived: append the assistant turn in place (typed out), without a blanking re-fetch.
     */
    async _onReplyArrived() {
        // Note whether the optimistic question bubble is present BEFORE any await. If a fast reply beat
        // the optimistic render (see _onSending), it is absent and we render the user turn from the
        // server payload below; otherwise it is already shown and we just keep it in place.
        const hadpending = this.element.querySelector('.gai-pending') !== null;
        this.element.querySelectorAll('.gai-typing').forEach((node) => node.remove());
        this.element.querySelectorAll('.gai-pending').forEach((node) => node.classList.remove('gai-pending'));

        let msgs = [];
        try {
            msgs = JSON.parse(this.reactive.state.config.lastreply || '[]');
        } catch (error) {
            msgs = [];
        }
        const [you, assistant] = await getStrings([
            {key: 'youlabel', component: 'local_groundedai'},
            {key: 'assistantlabel', component: 'local_groundedai'},
        ]);
        // Render the whole new turn in document order: user question first (only when the optimistic
        // bubble did not land), then the typed assistant reply.
        for (const m of msgs) {
            const shaped = this._shape(m, you, assistant);
            if (m.role === 'assistant') {
                await this._appendAssistant(shaped);
            } else if (!hadpending) {
                await this._appendStatic(shaped);
            }
        }
        const ids = msgs.map((m) => parseInt(m.id, 10)).filter((n) => n > 0);
        if (ids.length) {
            this._newestId = Math.max(this._newestId, ...ids);
        }
        const replies = msgs.filter((m) => m.role === 'assistant');
        const last = replies.length ? replies[replies.length - 1] : null;
        const app = this.element.closest('.gai-app');
        const region = app ? app.querySelector('[data-region="gai-announce"]') : null;
        if (region && last) {
            region.textContent = (last.content || '').slice(0, 400);
        }
    }

    /**
     * A send failed: remove the optimistic question + typing cue (the composer restores the text).
     */
    _onSendError() {
        this._cancelTyper();
        this.element.querySelectorAll('.gai-pending, .gai-typing').forEach((node) => node.remove());
    }

    /**
     * The user hit "stop": abandon the in-flight turn in the view — stop any typing animation and drop
     * the optimistic question + typing cue. The turn stays in history and reappears if the chat reopens.
     */
    _onCancelled() {
        this._cancelTyper();
        this.element.querySelectorAll('.gai-pending, .gai-typing').forEach((node) => node.remove());
    }

    /**
     * Append one assistant bubble and type its text out, then swap in the formatted markdown.
     *
     * @param {Object} shaped A shaped assistant message.
     */
    async _appendAssistant(shaped) {
        const {html, js} = await Templates.renderForPromise('local_groundedai/panel_content',
            {messages: [shaped], empty: false, hasmore: false});
        const holder = document.createElement('div');
        holder.innerHTML = html;
        const bubble = holder.querySelector('.gai-msg');
        while (holder.firstChild) {
            this.element.appendChild(holder.firstChild);
        }
        if (js) {
            Templates.runTemplateJS(js);
        }
        const content = bubble ? bubble.querySelector('.gai-msg-content') : null;
        if (content) {
            this._typewrite(content, shaped.content, shaped.hashtml ? shaped.contenthtml : '');
        } else {
            this._scrollToBottom();
        }
    }

    /**
     * Append one turn shown in full at once (no typewriter) — used for the user's question when the
     * optimistic bubble did not land, so the reply handler can post it from the server payload.
     *
     * @param {Object} shaped A shaped message.
     */
    async _appendStatic(shaped) {
        const {html, js} = await Templates.renderForPromise('local_groundedai/panel_content',
            {messages: [shaped], empty: false, hasmore: false});
        const holder = document.createElement('div');
        holder.innerHTML = html;
        while (holder.firstChild) {
            this.element.appendChild(holder.firstChild);
        }
        if (js) {
            Templates.runTemplateJS(js);
        }
        this._applyFilters(this.element);
        this._scrollToBottom();
    }

    /**
     * Reveal the answer smoothly: put the formatted HTML in place, then fill its text nodes in
     * document order over a time-based budget. The formatting is present from the first frame — no raw
     * markdown and no end-of-type swap. A constant reading cadence (~200 chars/sec, see below), paced with
     * requestAnimationFrame so it is frame-rate-independent and recovers if the tab was backgrounded.
     * Honours reduced-motion. A setTimeout safety-net guarantees completion even where rAF is paused
     * (a throttled or non-painting tab). (Speed/technique from industry best-practice research.)
     *
     * @param {HTMLElement} element The content node.
     * @param {String} text Plain-text fallback when there is no formatted HTML.
     * @param {String} finalHtml The server-rendered formatted HTML.
     */
    _typewrite(element, text, finalHtml) {
        this._cancelTyper();
        const reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const html = finalHtml || '';
        if (!html) {
            element.textContent = String(text || '');
            this._scrollToBottom();
            return;
        }
        // Formatting is correct from frame 0; we only ever fill text-node values, never rebuild tags.
        element.innerHTML = html;
        // Reduced-motion, or a hidden tab (rAF is paused there) → show the full answer at once, no animation.
        if (reduced || document.hidden) {
            this._applyFilters(element);
            this._scrollToBottom();
            return;
        }

        const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
        const nodes = [];
        let total = 0;
        let node;
        while ((node = walker.nextNode())) {
            const value = node.nodeValue;
            if (!value || !/\S/.test(value)) {
                continue;   // Whitespace-only nodes stay visible — no dead pauses, no skewed budget.
            }
            nodes.push({node, text: value, block: this._blockOf(node, element)});
            node.nodeValue = '';
            total += value.length;
        }
        if (!total) {
            this._scrollToBottom();
            return;
        }

        element.classList.add('gai-typing-text');
        // Reveal at one steady, Claude/ChatGPT-like cadence rather than a fixed total: a brief floor for
        // tiny answers, a constant ~50 tokens/sec base for the body, and a gentle (still constant) faster
        // tail past the knee so very long answers don't drag. A fixed ms ceiling is deliberately avoided —
        // it made long answers accelerate (the "too fast" feel). (Cadence from industry-baseline research.)
        const FLOORMS = 420;
        const CPSBASE = 200;
        const KNEECHARS = 1100;
        const CPSTAIL = 420;
        const duration = total <= KNEECHARS
            ? Math.max(FLOORMS, (total / CPSBASE) * 1000)
            : (KNEECHARS / CPSBASE) * 1000 + ((total - KNEECHARS) / CPSTAIL) * 1000;
        let idx = 0;
        let pos = 0;
        let start = 0;
        const seen = new Set();
        const nearBottom = () =>
            (this.element.scrollHeight - this.element.scrollTop - this.element.clientHeight) < 96;

        // Finish the reveal in one shot: fill every node, drop the caret, and release both timers.
        // Idempotent, so the natural end, a cancel (conversation switch), and the safety-net all share it.
        let finished = false;
        const finish = () => {
            if (finished) {
                return;
            }
            finished = true;
            nodes.forEach((item) => {
                item.node.nodeValue = item.text;
            });
            element.classList.remove('gai-typing-text');
            if (this._typer) {
                cancelAnimationFrame(this._typer);
                this._typer = null;
            }
            if (this._typerTimeout) {
                clearTimeout(this._typerTimeout);
                this._typerTimeout = null;
            }
            this._typerDone = null;
            this._applyFilters(element);   // Typeset any math now the full text is in place.
            this._scrollToBottom();
        };
        this._typerDone = finish;

        const tick = (ts) => {
            if (!start) {
                start = ts;
            }
            const target = Math.floor(total * Math.min(1, (ts - start) / duration));
            const stick = nearBottom();
            let shown = pos;
            for (let k = 0; k < idx; k++) {
                shown += nodes[k].text.length;
            }
            let reveal = target - shown;
            if (reveal > 0) {
                while (reveal > 0 && idx < nodes.length) {
                    const item = nodes[idx];
                    if (item.block && !seen.has(item.block)) {
                        seen.add(item.block);
                        item.block.classList.add('gai-rise');
                    }
                    const take = Math.min(reveal, item.text.length - pos);
                    pos += take;
                    reveal -= take;
                    item.node.nodeValue = item.text.slice(0, pos);
                    if (pos >= item.text.length) {
                        idx++;
                        pos = 0;
                    }
                }
                if (stick) {
                    this._scrollToBottom();
                }
            }
            if (idx < nodes.length) {
                this._typer = requestAnimationFrame(tick);
            } else {
                finish();
            }
        };
        this._typer = requestAnimationFrame(tick);
        // rAF is paused in a hidden or non-painting tab; setTimeout still fires, so the answer always lands.
        this._typerTimeout = setTimeout(finish, Math.ceil(duration) + 500);
    }

    /**
     * The nearest top-level block (a direct child of the content element) for the per-block ease-in.
     *
     * @param {Node} node A text node.
     * @param {HTMLElement} root The content element.
     * @returns {HTMLElement|null}
     */
    _blockOf(node, root) {
        let el = node.parentNode;
        while (el && el.parentNode !== root) {
            el = el.parentNode;
        }
        return (el && el.nodeType === 1) ? el : null;
    }

    /**
     * Stop any in-progress reveal, completing it to the full text so nothing is left half-blank.
     */
    _cancelTyper() {
        if (this._typerDone) {
            this._typerDone();
            this._typerDone = null;
        }
        if (this._typer) {
            cancelAnimationFrame(this._typer);
            this._typer = null;
        }
        if (this._typerTimeout) {
            clearTimeout(this._typerTimeout);
            this._typerTimeout = null;
        }
    }

    /**
     * Pin to the newest message.
     */
    _scrollToBottom() {
        this.element.scrollTop = this.element.scrollHeight;
    }

    /**
     * Let Moodle's text filters process freshly-injected content — notably MathJax, so LaTeX in a reply
     * is typeset. Best-effort: if no filter is listening (e.g. MathJax not enabled) it is a no-op and the
     * delimiters simply remain as text.
     *
     * @param {HTMLElement} element The element whose content just became final.
     */
    _applyFilters(element) {
        if (!element) {
            return;
        }
        try {
            notifyFilterContentUpdated([element]);
        } catch (error) {
            // Filters are best-effort; ignore if the event bus is unavailable.
        }
    }
}
