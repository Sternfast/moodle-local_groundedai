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
 * Message composer: textarea + send, an attach control, Enter-to-send, and auto-grow.
 *
 * @module     local_groundedai/components/composer
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {BaseComponent} from 'core/reactive';
import {getString, getStrings} from 'core/str';
import Notification from 'core/notification';
import {isSessionError} from 'local_groundedai/repository';

const ICON_SEND = '↑';   // Up arrow: "send".
const ICON_STOP = '■';   // Filled square: "stop generating".

export default class extends BaseComponent {

    /**
     * Component descriptor.
     */
    create() {
        this.name = 'local_groundedai_composer';
        this.selectors = {
            INPUT: '[data-region="gai-input"]',
            SEND: '[data-action="send"]',
            SENDICON: '[data-region="gai-send-icon"]',
            ATTACH: '[data-action="gai-attach"]',
            FILE: '[data-region="gai-fileinput"]',
        };
        this._uploading = false;
        this._labels = {send: 'Send', stop: 'Stop'};
    }

    /**
     * Wire the form.
     */
    async stateReady() {
        const input = this.getElement(this.selectors.INPUT);
        const [send, stop] = await getStrings([
            {key: 'send', component: 'local_groundedai'},
            {key: 'stop', component: 'local_groundedai'},
        ]);
        this._labels = {send, stop};

        this.addEventListener(this.element, 'submit', this._onSubmit);
        this.addEventListener(input, 'keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                this._submit();
            }
        });
        // Grow to fit, and keep the send button muted until there is something to send.
        this.addEventListener(input, 'input', () => {
            this._autogrow();
            this._updateSendButton();
        });

        const attach = this.getElement(this.selectors.ATTACH);
        const file = this.getElement(this.selectors.FILE);
        if (attach && file) {
            this.addEventListener(attach, 'click', () => file.click());
            this.addEventListener(file, 'change', (e) => this._onFile(e));
        }
        this._refreshEnabled();
    }

    /**
     * Watchers.
     *
     * @returns {Array}
     */
    getWatchers() {
        return [
            {watch: 'config.sending:updated', handler: this._refreshEnabled},
            {watch: 'config.currentConversationId:updated', handler: this._refreshEnabled},
        ];
    }

    /**
     * Handle the form submit: send when idle, or abandon the turn when one is generating (the same
     * button is the stop control while the assistant is replying).
     *
     * @param {Event} e The submit event.
     */
    _onSubmit(e) {
        e.preventDefault();
        if (this.reactive.state.config.sending) {
            this.reactive.dispatch('cancelSend');
            return;
        }
        this._submit();
    }

    /**
     * Read the text and dispatch it, restoring it if the send fails.
     */
    async _submit() {
        const input = this.getElement(this.selectors.INPUT);
        const text = input.value.trim();
        if (text === '' || this.reactive.state.config.sending || !this.reactive.state.config.currentConversationId) {
            return;
        }
        input.value = '';
        this._autogrow();
        this._updateSendButton();
        try {
            await this.reactive.dispatch('sendMessage', text);
        } catch (error) {
            // Restore the drafted text, re-enable Send (the sending flag flips it off), and surface the
            // failure. A session error is already handled by the repository's reload prompt, so skip the
            // extra modal for that case.
            if (!input.value) {
                input.value = text;
                this._autogrow();
            }
            this._updateSendButton();
            if (!isSessionError(error)) {
                Notification.exception(error);
            }
        }
    }

    /**
     * Validate and upload the chosen file.
     *
     * @param {Event} e The change event.
     */
    async _onFile(e) {
        const file = e.target.files && e.target.files[0];
        e.target.value = '';
        if (!file || this._uploading) {
            return;
        }
        if (!this.reactive.state.config.currentConversationId) {
            return;
        }
        const max = parseInt(this.reactive.state.static.maxbytes, 10);
        if (max && file.size > max) {
            Notification.alert(
                await getString('attachfile', 'local_groundedai'),
                await getString('uploadtoobig', 'local_groundedai', Math.round(max / 1048576))
            );
            return;
        }
        this._uploading = true;
        this._setAttachBusy(true);
        try {
            await this.reactive.dispatch('uploadAttachment', file);
        } catch (error) {
            Notification.exception(error);
        } finally {
            this._uploading = false;
            this._setAttachBusy(false);
        }
    }

    /**
     * Toggle the attach button's busy state.
     *
     * @param {Boolean} busy Whether an upload is in flight.
     */
    _setAttachBusy(busy) {
        const attach = this.getElement(this.selectors.ATTACH);
        if (attach) {
            attach.disabled = busy;
            attach.classList.toggle('gai-attach-busy', busy);
        }
    }

    /**
     * Grow the textarea to fit its content, up to its max height.
     */
    _autogrow() {
        const input = this.getElement(this.selectors.INPUT);
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 160) + 'px';
    }

    /**
     * Enable the input (and attach) only when a conversation is open and nothing is in flight, and
     * return focus to the input when it becomes usable again (so keyboard users can type the next
     * turn). The send button is handled separately by _updateSendButton so it can stay live as the
     * "stop" control while the assistant replies.
     */
    _refreshEnabled() {
        const config = this.reactive.state.config;
        const disabled = config.sending || !config.currentConversationId;
        const input = this.getElement(this.selectors.INPUT);
        const wasdisabled = input.disabled;
        input.disabled = disabled;
        const attach = this.getElement(this.selectors.ATTACH);
        if (attach && !this._uploading) {
            attach.disabled = disabled;
        }
        this._updateSendButton();
        if (wasdisabled && !disabled) {
            input.focus();
        }
    }

    /**
     * Reflect the composer state on the one send/stop button:
     *  - generating  → a live "stop" control (filled square), so a slow turn can be abandoned;
     *  - idle, empty → muted (nothing to send yet);
     *  - idle, typed → an active "send" control (up arrow).
     */
    _updateSendButton() {
        const send = this.getElement(this.selectors.SEND);
        if (!send) {
            return;
        }
        const config = this.reactive.state.config;
        const icon = this.getElement(this.selectors.SENDICON);
        if (config.sending) {
            send.disabled = false;
            send.classList.add('gai-stop');
            send.setAttribute('aria-label', this._labels.stop);
            send.setAttribute('title', this._labels.stop);
            if (icon) {
                icon.textContent = ICON_STOP;
            }
            return;
        }
        const input = this.getElement(this.selectors.INPUT);
        const hastext = Boolean(input && input.value.trim() !== '');
        send.disabled = !config.currentConversationId || !hastext;
        send.classList.remove('gai-stop');
        send.setAttribute('aria-label', this._labels.send);
        send.setAttribute('title', this._labels.send);
        if (icon) {
            icon.textContent = ICON_SEND;
        }
    }
}
