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
 * The only place the chat state is changed. Every write is fenced with setReadOnly so the reactive
 * library auto-dispatches the per-property watcher events.
 *
 * @module     local_groundedai/mutations
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import * as Repository from 'local_groundedai/repository';

/**
 * Chat mutations.
 */
export default class {

    /**
     * Switch the sidebar between the Chats and Archived lists.
     *
     * @param {StateManager} stateManager The state manager.
     * @param {String} view 'chats' or 'archived'.
     */
    setView(stateManager, view) {
        stateManager.setReadOnly(false);
        stateManager.state.config.view = view;
        stateManager.setReadOnly(true);
    }

    /**
     * Start a new conversation and open it.
     *
     * @param {StateManager} stateManager The state manager.
     */
    async newConversation(stateManager) {
        const {contextid, lane} = stateManager.state.static;
        const result = await Repository.createConversation(lane, contextid);
        stateManager.setReadOnly(false);
        stateManager.state.config.view = 'chats';
        stateManager.state.config.currentConversationId = parseInt(result.id, 10);
        stateManager.state.config.listdirty++;
        stateManager.setReadOnly(true);
    }

    /**
     * Open an existing conversation.
     *
     * @param {StateManager} stateManager The state manager.
     * @param {Number} id Conversation id.
     */
    openConversation(stateManager, id) {
        stateManager.setReadOnly(false);
        stateManager.state.config.currentConversationId = parseInt(id, 10);
        stateManager.setReadOnly(true);
    }

    /**
     * Rename a conversation.
     *
     * @param {StateManager} stateManager The state manager.
     * @param {Number} id Conversation id.
     * @param {String} name New title.
     */
    async renameConversation(stateManager, id, name) {
        await Repository.renameConversation(id, name);
        stateManager.setReadOnly(false);
        stateManager.state.config.listdirty++;
        stateManager.setReadOnly(true);
    }

    /**
     * Archive or unarchive a conversation.
     *
     * @param {StateManager} stateManager The state manager.
     * @param {Number} id Conversation id.
     * @param {Boolean} archived Target state.
     */
    async archiveConversation(stateManager, id, archived) {
        await Repository.archiveConversation(id, archived);
        stateManager.setReadOnly(false);
        if (parseInt(stateManager.state.config.currentConversationId, 10) === parseInt(id, 10)) {
            stateManager.state.config.currentConversationId = 0;
        }
        stateManager.state.config.listdirty++;
        stateManager.setReadOnly(true);
    }

    /**
     * Delete a conversation.
     *
     * @param {StateManager} stateManager The state manager.
     * @param {Number} id Conversation id.
     */
    async deleteConversation(stateManager, id) {
        await Repository.deleteConversation(id);
        stateManager.setReadOnly(false);
        if (parseInt(stateManager.state.config.currentConversationId, 10) === parseInt(id, 10)) {
            stateManager.state.config.currentConversationId = 0;
        }
        stateManager.state.config.listdirty++;
        stateManager.setReadOnly(true);
    }

    /**
     * Upload a file and attach it to the open conversation.
     *
     * @param {StateManager} stateManager The state manager.
     * @param {File} file The chosen file.
     */
    async uploadAttachment(stateManager, file) {
        const staticstate = stateManager.state.static;
        const id = parseInt(stateManager.state.config.currentConversationId, 10);
        if (!id) {
            return;
        }
        // A fresh draft area per upload, so file_save_draft_area_files (maxfiles=1) never re-copies
        // an earlier file when several are attached in one page session.
        const prep = await Repository.prepareUpload(id);
        const draftitemid = parseInt(prep.draftitemid, 10);
        await Repository.uploadToDraft(file, {
            repoid: staticstate.repoid,
            usercontextid: staticstate.usercontextid,
            draftitemid,
        });
        await Repository.uploadFile(id, draftitemid);
        stateManager.setReadOnly(false);
        stateManager.state.config.attachmentsdirty++;
        stateManager.setReadOnly(true);
    }

    /**
     * Remove an attachment from the open conversation.
     *
     * @param {StateManager} stateManager The state manager.
     * @param {Number} attachmentid Attachment id.
     */
    async deleteAttachment(stateManager, attachmentid) {
        const id = parseInt(stateManager.state.config.currentConversationId, 10);
        if (!id) {
            return;
        }
        await Repository.deleteAttachment(id, attachmentid);
        stateManager.setReadOnly(false);
        stateManager.state.config.attachmentsdirty++;
        stateManager.setReadOnly(true);
    }

    /**
     * Abandon the turn that is generating. The backend call is a single request/response with no
     * server-side abort, so "stop" means: return the composer to ready at once and discard the reply
     * from the view when it lands (it is still saved to history, and reappears if the chat is reopened).
     *
     * @param {StateManager} stateManager The state manager.
     */
    cancelSend(stateManager) {
        if (!stateManager.state.config.sending) {
            return;
        }
        stateManager.setReadOnly(false);
        stateManager.state.config.cancelseq++;
        stateManager.state.config.sending = false;
        stateManager.state.config.pendinguser = '';
        stateManager.setReadOnly(true);
    }

    /**
     * Send a message in the open conversation.
     *
     * @param {StateManager} stateManager The state manager.
     * @param {String} text The message.
     */
    async sendMessage(stateManager, text) {
        const id = parseInt(stateManager.state.config.currentConversationId, 10);
        if (!id) {
            return;
        }
        const {cmid, pagetype} = stateManager.state.static;
        // Snapshot the cancel token: if it moves while we await, the user hit "stop" and the reply is stale.
        const token = parseInt(stateManager.state.config.cancelseq, 10);
        stateManager.setReadOnly(false);
        stateManager.state.config.pendinguser = text;
        stateManager.state.config.sending = true;
        stateManager.setReadOnly(true);
        try {
            const result = await Repository.sendMessage(id, text, cmid, pagetype);
            if (parseInt(stateManager.state.config.cancelseq, 10) !== token) {
                // Cancelled mid-flight: drop the reply from the view (it is in history); just refresh the list.
                stateManager.setReadOnly(false);
                stateManager.state.config.listdirty++;
                stateManager.setReadOnly(true);
                return;
            }
            stateManager.setReadOnly(false);
            // Hand the new turn to the panel so it appends it in place — no full re-fetch, no flash.
            stateManager.state.config.lastreply = JSON.stringify(result.messages || []);
            stateManager.state.config.replyseq++;
            stateManager.state.config.sending = false;
            stateManager.state.config.pendinguser = '';
            stateManager.state.config.listdirty++;
            stateManager.setReadOnly(true);
        } catch (error) {
            if (parseInt(stateManager.state.config.cancelseq, 10) !== token) {
                // The user already abandoned this turn; swallow the error quietly.
                return;
            }
            stateManager.setReadOnly(false);
            stateManager.state.config.sending = false;
            stateManager.state.config.pendinguser = '';
            stateManager.state.config.senderror++;
            stateManager.setReadOnly(true);
            throw error;
        }
    }
}
