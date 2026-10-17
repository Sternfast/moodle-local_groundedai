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
 * Conversation sidebar: time-bucketed list, hover/focus row actions, inline rename, active cue.
 *
 * @module     local_groundedai/components/list
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {BaseComponent} from 'core/reactive';
import Templates from 'core/templates';
import {getStrings} from 'core/str';
import Notification from 'core/notification';
import * as Repository from 'local_groundedai/repository';

export default class extends BaseComponent {

    /**
     * Component descriptor.
     */
    create() {
        this.name = 'local_groundedai_list';
        this._refocus = null;
    }

    /**
     * Wire delegation once; the container persists across re-renders.
     */
    async stateReady() {
        this.addEventListener(this.element, 'click', this._onClick);
        // Close any open row menu on an outside click or Escape.
        this.addEventListener(document, 'click', (e) => {
            if (!e.target.closest('.gai-conv-menu')) {
                this._closeMenus();
            }
        });
        this.addEventListener(this.element, 'keydown', (e) => {
            if (e.key === 'Escape') {
                this._closeMenus();
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
            {watch: 'config.view:updated', handler: this._render},
            {watch: 'config.listdirty:updated', handler: this._render},
            {watch: 'config.currentConversationId:updated', handler: this._markActive},
        ];
    }

    /**
     * Fetch and render the whole list for the current view.
     */
    async _render() {
        const {contextid, lane} = this.reactive.state.static;
        const archived = this.reactive.state.config.view === 'archived';
        let convs;
        try {
            convs = await Repository.getConversations(lane, contextid, archived);
        } catch (error) {
            Notification.exception(error);
            return;
        }
        const groups = await this._group(convs, archived);
        const {html, js} = await Templates.renderForPromise('local_groundedai/list_content',
            {groups, empty: convs.length === 0});
        Templates.replaceNodeContents(this.element, html, js);
        this._markActive();
        this._restoreFocus();
    }

    /**
     * After a destructive re-render, return focus to a sensible element rather than letting it
     * fall to the document body (so keyboard and screen-reader users keep their place).
     */
    _restoreFocus() {
        const target = this._refocus;
        this._refocus = null;
        if (!target) {
            return;
        }
        let node = null;
        if (target === 'newchat') {
            const sidebar = this.element.closest('.gai-sidebar');
            node = sidebar ? sidebar.querySelector('[data-action="newchat"]') : null;
        } else {
            node = this.element.querySelector('[data-conv-open="' + target + '"]');
        }
        if (node) {
            node.focus();
        }
    }

    /**
     * Bucket conversations by last activity.
     *
     * @param {Array} convs Conversation rows.
     * @param {Boolean} archived Whether this is the archived view.
     * @returns {Array} Non-empty groups.
     */
    async _group(convs, archived) {
        const now = new Date();
        const startofday = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime() / 1000;
        const day = 86400;
        const defs = [
            {key: 'today', min: startofday},
            {key: 'yesterday', min: startofday - day},
            {key: 'last7', min: startofday - (7 * day)},
            {key: 'last30', min: startofday - (30 * day)},
            {key: 'older', min: 0},
        ];
        const labels = await getStrings(defs.map((d) => ({key: 'bucket_' + d.key, component: 'local_groundedai'})));
        const out = defs.map((d, i) => ({label: labels[i], items: []}));
        convs.forEach((conv) => {
            for (let i = 0; i < defs.length; i++) {
                if (conv.timemodified >= defs[i].min) {
                    out[i].items.push({id: conv.id, name: conv.name, archived});
                    break;
                }
            }
        });
        return out.filter((group) => group.items.length > 0);
    }

    /**
     * Delegated click handler.
     *
     * @param {Event} e The click event.
     */
    _onClick(e) {
        const open = e.target.closest('[data-conv-open]');
        if (open) {
            this.reactive.dispatch('openConversation', open.dataset.convOpen);
            return;
        }
        const dots = e.target.closest('[data-conv-menu]');
        if (dots) {
            e.stopPropagation();   // Keep the outside-click closer from immediately re-closing it.
            this._toggleMenu(dots);
            return;
        }
        const action = e.target.closest('[data-conv-action]');
        if (!action) {
            return;
        }
        this._closeMenus();
        const id = action.dataset.id;
        switch (action.dataset.convAction) {
            case 'rename':
                this._startRename(action.closest('[data-conv-id]'), id);
                break;
            case 'archive':
                this._refocus = 'newchat';
                this.reactive.dispatch('archiveConversation', id, true);
                break;
            case 'unarchive':
                this._refocus = 'newchat';
                this.reactive.dispatch('archiveConversation', id, false);
                break;
            case 'delete':
                this._confirmDelete(id);
                break;
        }
    }

    /**
     * Open one row's action menu (and close any other). The menu holds Rename / Archive / Delete as
     * words, so the row shows only an unobtrusive three-dot button until it is needed.
     *
     * @param {HTMLElement} dots The three-dot toggle button.
     */
    _toggleMenu(dots) {
        const dropdown = dots.parentElement.querySelector('.gai-conv-dropdown');
        const willopen = dropdown.hidden;
        this._closeMenus();
        if (willopen) {
            dropdown.hidden = false;
            dots.setAttribute('aria-expanded', 'true');
        }
    }

    /**
     * Close every open row action menu.
     */
    _closeMenus() {
        this.element.querySelectorAll('.gai-conv-dropdown').forEach((d) => {
            d.hidden = true;
        });
        this.element.querySelectorAll('[data-conv-menu]').forEach((b) => {
            b.setAttribute('aria-expanded', 'false');
        });
    }

    /**
     * Turn a row title into an inline text input.
     *
     * @param {HTMLElement} row The conversation row.
     * @param {Number} id Conversation id.
     */
    _startRename(row, id) {
        const title = row.querySelector('[data-conv-open]');
        if (!title) {
            return;
        }
        const current = title.textContent.trim();
        const input = document.createElement('input');
        input.type = 'text';
        input.value = current;
        input.className = 'gai-rename-input form-control form-control-sm';
        let done = false;
        const commit = () => {
            if (done) {
                return;
            }
            done = true;
            this._refocus = id;
            const value = input.value.trim();
            if (value !== '' && value !== current) {
                this.reactive.dispatch('renameConversation', id, value);
            } else {
                this._render();
            }
        };
        input.addEventListener('keydown', (ev) => {
            if (ev.key === 'Enter') {
                ev.preventDefault();
                commit();
            } else if (ev.key === 'Escape') {
                done = true;
                this._refocus = id;
                this._render();
            }
        });
        input.addEventListener('blur', commit);
        title.replaceWith(input);
        input.focus();
        input.select();
    }

    /**
     * Confirm then delete.
     *
     * @param {Number} id Conversation id.
     */
    _confirmDelete(id) {
        getStrings([
            {key: 'deletechat', component: 'local_groundedai'},
            {key: 'deletechatconfirm', component: 'local_groundedai'},
        ]).then(([title, question]) =>
            Notification.deleteCancelPromise(title, question)
        ).then(() => {
            this._refocus = 'newchat';
            this.reactive.dispatch('deleteConversation', id);
            return;
        }).catch(() => {
            return;
        });
    }

    /**
     * Mark the open conversation's row.
     */
    _markActive() {
        const id = String(this.reactive.state.config.currentConversationId);
        this.element.querySelectorAll('[data-conv-id]').forEach((row) => {
            row.classList.toggle('gai-active', row.dataset.convId === id);
        });
    }
}
