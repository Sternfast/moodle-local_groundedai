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
 * Staff shell: mount the shared chat core full-page (compact history left, chat right).
 *
 * This is the full-page home for the assistant (the floating popup is the quick, page-aware entry),
 * so there is no modal/overlay plumbing — just mount the reactive app and pass the upload config in.
 *
 * @module     local_groundedai/shell_staff
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {init as reactiveInit} from 'local_groundedai/reactive_init';

/**
 * Mount the staff assistant into the page container.
 *
 * @param {Object} cfg {contextid, canupload, repoid, usercontextid, draftitemid, maxbytes}.
 */
export const init = (cfg) => {
    const mount = document.querySelector('[data-region="gai-staffmount"]');
    if (!mount) {
        return;
    }
    reactiveInit(mount, {
        contextid: cfg.contextid,
        lane: 'staff',
        canupload: cfg.canupload,
        repoid: cfg.repoid,
        usercontextid: cfg.usercontextid,
        draftitemid: cfg.draftitemid,
        maxbytes: cfg.maxbytes,
    });
    fitToViewport(mount);
};

/**
 * Size the full-page card so the whole page fits the viewport: only the chat panel scrolls, never the
 * page itself (the page-plus-chat double scroll felt dizzy). It measures the real space the Moodle
 * header takes above the card and the footer takes below it — so it is correct on any theme/width,
 * unlike a fixed CSS reserve — and re-fits on resize. The CSS height remains the fallback.
 *
 * @param {HTMLElement} mount The staff-page card element.
 */
const fitToViewport = (mount) => {
    const apply = () => {
        const rect = mount.getBoundingClientRect();
        const docheight = document.documentElement.scrollHeight;
        // Content below the card (footer etc.), independent of the card's own height.
        const below = Math.max(0, docheight - (window.scrollY + rect.bottom));
        const target = Math.round(window.innerHeight - rect.top - below - 8);
        // Keep it usable on short viewports; there the page may still scroll, which is acceptable.
        mount.style.height = Math.max(360, target) + 'px';
    };
    apply();
    // A second pass after layout settles catches late reflow (fonts, blocks drawer).
    window.requestAnimationFrame(apply);
    let pending = null;
    window.addEventListener('resize', () => {
        window.clearTimeout(pending);
        pending = window.setTimeout(apply, 120);
    });
};
