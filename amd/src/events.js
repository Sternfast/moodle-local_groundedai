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
 * Reactive event plumbing for the chat app.
 *
 * @module     local_groundedai/events
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {dispatchEvent} from 'core/event_dispatcher';

/** @property {Object} eventTypes The DOM event the reactive instance dispatches on each state change. */
export const eventTypes = {
    stateUpdated: 'local_groundedai/stateUpdated',
};

/**
 * Dispatch the state-updated event.
 *
 * @param {Object} detail The change detail.
 * @param {HTMLElement} container The element to dispatch on.
 * @returns {CustomEvent}
 */
export const notify = (detail, container) => dispatchEvent(eventTypes.stateUpdated, detail, container);
