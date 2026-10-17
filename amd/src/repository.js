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
 * Thin wrapper over the chat web-service functions.
 *
 * @module     local_groundedai/repository
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {call as fetchMany} from 'core/ajax';
import Config from 'core/config';
import Notification from 'core/notification';
import {get_string as getString} from 'core/str';

// Error codes core returns when the Moodle session / sesskey is no longer valid (the "~1 hour" case).
const SESSION_CODES = ['servicerequireslogin', 'invalidsesskey', 'sessionerroruser', 'requireloginerror'];
let sessionPromptShown = false;

/**
 * Is this a session/sesskey failure (as opposed to an ordinary application error)?
 *
 * @param {Object} error The rejection from core/ajax.
 * @returns {Boolean}
 */
export const isSessionError = (error) => {
    if (!error) {
        return false;
    }
    const code = error.errorcode || (error.error && error.error.errorcode) || '';
    return SESSION_CODES.indexOf(code) !== -1;
};

/**
 * Wrap a web-service promise so a session/sesskey failure surfaces a single "session expired — reload"
 * prompt instead of a silent, dead UI. Any other error is re-thrown for the caller's own handling. Writes
 * are never auto-retried (a duplicate could double-post), so this only classifies and prompts.
 *
 * @param {Promise} promise A core/ajax call promise.
 * @returns {Promise}
 */
const guard = (promise) => promise.catch((error) => {
    if (isSessionError(error) && !sessionPromptShown) {
        sessionPromptShown = true;
        Promise.all([
            getString('sessionexpired', 'local_groundedai'),
            getString('sessionexpired_body', 'local_groundedai'),
            getString('sessionexpired_reload', 'local_groundedai'),
            getString('cancel', 'core'),
        ]).then(([title, body, yes, no]) => {
            Notification.confirm(title, body, yes, no, () => window.location.reload());
            return;
        }).catch(() => {
            if (window.confirm('Your session has expired. Reload the page to continue?')) {
                window.location.reload();
            }
        });
    }
    throw error;
});

/**
 * List conversations.
 *
 * @param {String} lane student or staff.
 * @param {Number} contextid Context id.
 * @param {Boolean} archived Archived set?
 * @returns {Promise}
 */
export const getConversations = (lane, contextid, archived) =>
    guard(fetchMany([{methodname: 'local_groundedai_get_conversations', args: {lane, contextid, archived}}])[0]);

/**
 * Create a conversation.
 *
 * @param {String} lane student or staff.
 * @param {Number} contextid Context id.
 * @returns {Promise}
 */
export const createConversation = (lane, contextid) =>
    guard(fetchMany([{methodname: 'local_groundedai_create_conversation', args: {lane, contextid}}])[0]);

/**
 * Rename a conversation.
 *
 * @param {Number} id Conversation id.
 * @param {String} name New title.
 * @returns {Promise}
 */
export const renameConversation = (id, name) =>
    guard(fetchMany([{methodname: 'local_groundedai_rename_conversation', args: {id, name}}])[0]);

/**
 * Archive or unarchive a conversation.
 *
 * @param {Number} id Conversation id.
 * @param {Boolean} archived Target state.
 * @returns {Promise}
 */
export const archiveConversation = (id, archived) =>
    guard(fetchMany([{methodname: 'local_groundedai_archive_conversation', args: {id, archived}}])[0]);

/**
 * Delete a conversation.
 *
 * @param {Number} id Conversation id.
 * @returns {Promise}
 */
export const deleteConversation = (id) =>
    guard(fetchMany([{methodname: 'local_groundedai_delete_conversation', args: {id}}])[0]);

/**
 * Fetch a page of messages.
 *
 * @param {Number} conversationid Conversation id.
 * @param {Number} before Cursor.
 * @param {Number} limit Page size.
 * @param {Number} after Refresh cursor: messages with id greater than this (0 = off).
 * @returns {Promise}
 */
export const getMessages = (conversationid, before, limit, after = 0) =>
    guard(fetchMany([{methodname: 'local_groundedai_get_messages', args: {conversationid, before, limit, after}}])[0]);

/**
 * Send a message.
 *
 * @param {Number} conversationid Conversation id.
 * @param {String} text The message.
 * @param {Number} cmid The activity being viewed, if any (advisory page hint).
 * @param {String} pagetype The page type being viewed, if any (advisory hint).
 * @returns {Promise}
 */
export const sendMessage = (conversationid, text, cmid = 0, pagetype = '') =>
    guard(fetchMany([{methodname: 'local_groundedai_send_message', args: {conversationid, text, cmid, pagetype}}])[0]);

/**
 * List a conversation's attachments.
 *
 * @param {Number} conversationid Conversation id.
 * @returns {Promise}
 */
export const getAttachments = (conversationid) =>
    guard(fetchMany([{methodname: 'local_groundedai_get_attachments', args: {conversationid}}])[0]);

/**
 * Allocate a fresh draft area for one upload.
 *
 * @param {Number} conversationid Conversation id.
 * @returns {Promise}
 */
export const prepareUpload = (conversationid) =>
    guard(fetchMany([{methodname: 'local_groundedai_prepare_upload', args: {conversationid}}])[0]);

/**
 * Attach an already-uploaded draft file to a conversation.
 *
 * @param {Number} conversationid Conversation id.
 * @param {Number} draftitemid Draft item id.
 * @returns {Promise}
 */
export const uploadFile = (conversationid, draftitemid) =>
    guard(fetchMany([{methodname: 'local_groundedai_upload_file', args: {conversationid, draftitemid}}])[0]);

/**
 * Remove an attachment.
 *
 * @param {Number} conversationid Conversation id.
 * @param {Number} attachmentid Attachment id.
 * @returns {Promise}
 */
export const deleteAttachment = (conversationid, attachmentid) =>
    guard(fetchMany([{methodname: 'local_groundedai_delete_attachment', args: {conversationid, attachmentid}}])[0]);

/**
 * Upload a file into the user's draft area via the core "Upload a file" repository
 * (the same endpoint the native filepicker uses). Returns the repository response.
 *
 * @param {File} file The chosen file.
 * @param {Object} cfg {repoid, usercontextid, draftitemid}.
 * @returns {Promise<Object>}
 */
export const uploadToDraft = async (file, cfg) => {
    const form = new FormData();
    form.append('repo_upload_file', file);
    form.append('sesskey', Config.sesskey);
    form.append('repo_id', cfg.repoid);
    form.append('itemid', cfg.draftitemid);
    form.append('author', '');
    form.append('savepath', '/');
    form.append('ctx_id', cfg.usercontextid);
    form.append('overwrite', 1);
    const response = await fetch(`${Config.wwwroot}/repository/repository_ajax.php?action=upload`, {
        method: 'POST',
        body: form,
        credentials: 'same-origin',
    });
    const data = await response.json();
    if (data && (data.error || data.errorcode)) {
        throw new Error(data.error || data.errorcode);
    }
    return data;
};
