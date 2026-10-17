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
 * Creates the reactive chat instance and mounts the app component.
 *
 * @module     local_groundedai/reactive_init
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {Reactive} from 'core/reactive';
import {eventTypes, notify} from 'local_groundedai/events';
import Mutations from 'local_groundedai/mutations';
import AppComponent from 'local_groundedai/components/app';

let instancecount = 0;

/**
 * Create a reactive chat instance mounted on a container.
 *
 * @param {HTMLElement} container The element to render the app into.
 * @param {Object} opts {contextid, courseid, cmid, pagetype, canupload, ...upload}.
 * @returns {Reactive} The reactive instance (so a shell can dispatch window-mode changes).
 */
export const init = (container, opts) => {
    const reactive = new Reactive({
        name: `local_groundedai_${instancecount++}`,
        eventName: eventTypes.stateUpdated,
        eventDispatch: notify,
        mutations: new Mutations(),
        state: {
            static: {
                contextid: parseInt(opts.contextid, 10),
                lane: 'staff',
                courseid: parseInt(opts.courseid || 0, 10),
                cmid: parseInt(opts.cmid || 0, 10),
                pagetype: String(opts.pagetype || ''),
                canupload: Boolean(opts.canupload),
                repoid: parseInt(opts.repoid || 0, 10),
                usercontextid: parseInt(opts.usercontextid || 0, 10),
                draftitemid: parseInt(opts.draftitemid || 0, 10),
                maxbytes: parseInt(opts.maxbytes || 0, 10),
            },
            config: {
                view: 'chats',
                currentConversationId: 0,
                sending: false,
                // Bumped when the user hits "stop": an in-flight send compares it and discards a late reply.
                cancelseq: 0,
                listdirty: 0,
                messagesdirty: 0,
                attachmentsdirty: 0,
                pendinguser: '',
                // Carries the reply straight from send() so the panel appends it (no blanking re-fetch).
                replyseq: 0,
                senderror: 0,
                lastreply: '',
            },
        },
    });
    new AppComponent({element: container, reactive});
    return reactive;
};
