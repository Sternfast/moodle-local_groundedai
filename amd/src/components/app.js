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
 * Root chat component: lays out the shell and coordinates the list, panel and composer.
 *
 * @module     local_groundedai/components/app
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {BaseComponent} from 'core/reactive';
import Templates from 'core/templates';
import LocalStorage from 'core/localstorage';
import ListComponent from 'local_groundedai/components/list';
import PanelComponent from 'local_groundedai/components/panel';
import ComposerComponent from 'local_groundedai/components/composer';
import AttachmentsComponent from 'local_groundedai/components/attachments';

const COLLAPSE_KEY = 'local_groundedai_sidebar_collapsed';

export default class extends BaseComponent {

    /**
     * Component descriptor.
     */
    create() {
        this.name = 'local_groundedai_app';
        this._inPopup = false;
        this.selectors = {
            LIST: '[data-region="gai-list"]',
            PANEL: '[data-region="gai-panel"]',
            COMPOSER: '[data-region="gai-composer"]',
            ATTACHMENTS: '[data-region="gai-attachments"]',
            NEWCHAT: '[data-action="newchat"]',
            VIEWTAB: '[data-action="view"]',
            COLLAPSE: '[data-action="gai-collapse"]',
            EXPAND: '[data-action="gai-expand"]',
        };
    }

    /**
     * State is ready: render the layout and mount the children.
     *
     * @param {Object} state The reactive state.
     */
    async stateReady(state) {
        const canupload = Boolean(this.reactive.state.static.canupload);
        const {html, js} = await Templates.renderForPromise('local_groundedai/app', {canupload});
        Templates.replaceNodeContents(this.element, html, js);

        new ListComponent({element: this.getElement(this.selectors.LIST), reactive: this.reactive});
        new PanelComponent({element: this.getElement(this.selectors.PANEL), reactive: this.reactive});
        new ComposerComponent({element: this.getElement(this.selectors.COMPOSER), reactive: this.reactive});
        if (canupload) {
            const attachregion = this.getElement(this.selectors.ATTACHMENTS);
            if (attachregion) {
                new AttachmentsComponent({element: attachregion, reactive: this.reactive});
            }
        }

        this.addEventListener(this.getElement(this.selectors.NEWCHAT), 'click',
            () => this.reactive.dispatch('newConversation'));
        this.getElements(this.selectors.VIEWTAB).forEach((tab) => {
            this.addEventListener(tab, 'click', () => this.reactive.dispatch('setView', tab.dataset.view));
        });
        // In the floating popup the list is a space-saving overlay: start collapsed and slide it away
        // again once a conversation is picked, so the chat always keeps the full (narrow) width.
        this._inPopup = Boolean(this.element.closest('.gai-popup'));
        this.addEventListener(this.getElement(this.selectors.COLLAPSE), 'click', () => this._setCollapsed(true));
        this.addEventListener(this.getElement(this.selectors.EXPAND), 'click', () => this._setCollapsed(false));
        this._setCollapsed(this._inPopup ? true : this._readCollapsed());
        this._syncTabs(state.config.view);
        // The panel opens on an empty draft (currentConversationId = 0): the composer is live and the row
        // is created only on the first message, so opening the popup never leaves an empty "New chat".
    }

    /**
     * Collapse or expand the conversation sidebar, persisting the choice per viewer.
     *
     * @param {Boolean} collapsed Whether the sidebar should be hidden.
     */
    _setCollapsed(collapsed) {
        // The rendered root (.gai-app) sits inside the mount element; the CSS keys off it.
        const root = this.element.classList.contains('gai-app')
            ? this.element
            : (this.element.querySelector('.gai-app') || this.element);
        root.classList.toggle('gai-collapsed', collapsed);
        if (this._inPopup) {
            // The popup's collapse state is ephemeral — never overwrite the full-page preference.
            return;
        }
        try {
            LocalStorage.set(COLLAPSE_KEY, collapsed ? '1' : '0');
        } catch (error) {
            // Per-viewer convenience only; ignore storage failures.
        }
    }

    /**
     * Read the persisted collapsed state.
     *
     * @returns {Boolean}
     */
    _readCollapsed() {
        try {
            return LocalStorage.get(COLLAPSE_KEY) === '1';
        } catch (error) {
            return false;
        }
    }

    /**
     * Watchers.
     *
     * @returns {Array}
     */
    getWatchers() {
        return [
            {watch: 'config.view:updated', handler: this._onViewChanged},
            {watch: 'config.currentConversationId:updated', handler: this._onConvOpened},
        ];
    }

    /**
     * Reflect the active tab.
     *
     * @param {Object} args The watcher payload.
     * @param {Object} args.element The changed config element.
     */
    _onViewChanged({element}) {
        this._syncTabs(element.view);
    }

    /**
     * In the popup, slide the list overlay away once a conversation is opened or a new chat starts.
     */
    _onConvOpened() {
        if (this._inPopup) {
            this._setCollapsed(true);
        }
    }

    /**
     * Mark the matching tab active.
     *
     * @param {String} view The active view.
     */
    _syncTabs(view) {
        this.getElements(this.selectors.VIEWTAB).forEach((tab) => {
            const active = tab.dataset.view === view;
            tab.classList.toggle('active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
        });
    }
}
