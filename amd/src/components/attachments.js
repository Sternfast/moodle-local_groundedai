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
 * Attachment chips: show each attached file's read status and allow removing it. The attach
 * control itself lives in the composer; this component only renders the chips row.
 *
 * Extraction happens in a background task, so a file starts "Reading…" and the strip polls until
 * every file settles.
 *
 * @module     local_groundedai/components/attachments
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {BaseComponent} from 'core/reactive';
import Templates from 'core/templates';
import {getString} from 'core/str';
import * as Repository from 'local_groundedai/repository';

const POLL_MS = 2500;
const SETTLED = ['done', 'failed', 'noext', 'notextlayer', 'rejected'];

export default class extends BaseComponent {

    /**
     * Component descriptor.
     */
    create() {
        this.name = 'local_groundedai_attachments';
        this.selectors = {
            REMOVE: '[data-action="gai-remove-attach"]',
        };
        this._pollTimer = null;
        this._renderToken = 0;
    }

    /**
     * Bind the remove delegation once on the persistent element, then render.
     */
    async stateReady() {
        this.addEventListener(this.element, 'click', (e) => {
            const remove = e.target.closest(this.selectors.REMOVE);
            if (remove) {
                this.reactive.dispatch('deleteAttachment', parseInt(remove.dataset.id, 10));
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
            {watch: 'config.currentConversationId:updated', handler: this._render},
            {watch: 'config.attachmentsdirty:updated', handler: this._render},
        ];
    }

    /**
     * Render the chips for the open conversation; poll while anything is still reading. Guarded so a
     * slow response for a conversation the user has left cannot repaint the current one.
     */
    async _render() {
        if (this._pollTimer) {
            clearTimeout(this._pollTimer);
            this._pollTimer = null;
        }
        const id = parseInt(this.reactive.state.config.currentConversationId, 10);
        const token = ++this._renderToken;
        if (!id) {
            this.element.innerHTML = '';
            return;
        }

        let rows = [];
        try {
            const result = await Repository.getAttachments(id);
            rows = result.attachments || [];
        } catch (error) {
            rows = [];
        }
        if (!this._current(token, id)) {
            return;
        }

        const attachments = [];
        let pending = false;
        for (const row of rows) {
            const settled = SETTLED.includes(row.status);
            if (!settled) {
                pending = true;
            }
            attachments.push({
                id: row.id,
                filename: row.filename,
                statuslabel: await getString('attachstatus_' + row.status, 'local_groundedai').catch(() => row.status),
                isdone: row.status === 'done',
                iserror: ['failed', 'rejected'].includes(row.status),
                ispending: !settled,
            });
        }

        const {html, js} = await Templates.renderForPromise('local_groundedai/attachments_content', {
            attachments,
            hasattachments: attachments.length > 0,
        });
        if (!this._current(token, id)) {
            return;
        }
        Templates.replaceNodeContents(this.element, html, js);

        if (pending) {
            this._pollTimer = setTimeout(() => this._render(), POLL_MS);
        }
    }

    /**
     * Whether a render started for (token, id) is still the current one.
     *
     * @param {Number} token The render token captured at the start.
     * @param {Number} id The conversation id captured at the start.
     * @returns {Boolean}
     */
    _current(token, id) {
        return token === this._renderToken
            && parseInt(this.reactive.state.config.currentConversationId, 10) === id;
    }

    /**
     * Clean up the poll timer.
     */
    destroy() {
        if (this._pollTimer) {
            clearTimeout(this._pollTimer);
            this._pollTimer = null;
        }
    }
}
