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
 * Staff shell: a non-modal floating popup that overlays the course page.
 *
 * It is deliberately NOT a Bootstrap modal — a plain fixed panel coexists with the course (no
 * backdrop, no focus trap, no body scroll lock). A single layout menu picks the size preset (dock /
 * window / full), and the panel is freely resizable by dragging its left or top edge; the chosen
 * preset and custom size are persisted in a single versioned localStorage key. The popup is seeded
 * with the current page so the assistant knows which course and activity the staff member is viewing.
 *
 * @module     local_groundedai/shell_popup
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Templates from 'core/templates';
import LocalStorage from 'core/localstorage';
import {getString} from 'core/str';
import {init as reactiveInit} from 'local_groundedai/reactive_init';

const STORAGE_KEY = 'local_groundedai_ui_v1';
const MODES = {window: 'gai-window', dockright: 'gai-dockright', openfull: 'gai-openfull'};
const MIN_W = 300;
const MIN_H = 300;

let panel = null;
let mounting = null;
let activeMode = 'dockright';

/**
 * Clamp a number to a range.
 *
 * @param {Number} value The value.
 * @param {Number} min Lower bound.
 * @param {Number} max Upper bound.
 * @returns {Number}
 */
const clamp = (value, min, max) => Math.max(min, Math.min(max, value));

/**
 * Read the persisted UI prefs (tolerant of empty / corrupt / blocked storage).
 *
 * @returns {Object}
 */
const readPrefs = () => {
    try {
        return JSON.parse(LocalStorage.get(STORAGE_KEY) || '{}') || {};
    } catch (error) {
        return {};
    }
};

/**
 * Persist UI prefs.
 *
 * @param {Object} patch Values to merge.
 */
const writePrefs = (patch) => {
    try {
        LocalStorage.set(STORAGE_KEY, JSON.stringify({...readPrefs(), ...patch}));
    } catch (error) {
        // Per-viewer convenience only; ignore storage failures.
    }
};

/**
 * Apply any persisted custom size as inline overrides, scoped to the modes they make sense in:
 * width for window + dock, height for window only. Full screen and phones ignore custom sizing.
 */
const applySize = () => {
    if (!panel) {
        return;
    }
    const prefs = readPrefs();
    const canWidth = (activeMode === 'window' || activeMode === 'dockright') && window.innerWidth > 576;
    const canHeight = activeMode === 'window' && window.innerWidth > 576;
    panel.style.width = (canWidth && prefs.width) ? clamp(prefs.width, MIN_W, window.innerWidth * 0.96) + 'px' : '';
    panel.style.height = (canHeight && prefs.height) ? clamp(prefs.height, MIN_H, window.innerHeight * 0.96) + 'px' : '';
};

/**
 * Mark the active preset in the layout menu.
 */
const updateLayoutMenu = () => {
    panel.querySelectorAll('[data-action="gai-mode"]').forEach((btn) => {
        const on = MODES[btn.dataset.mode] && panel.classList.contains(MODES[btn.dataset.mode]);
        btn.classList.toggle('active', Boolean(on));
        btn.setAttribute('aria-checked', on ? 'true' : 'false');
    });
};

/**
 * Apply a window mode (class swap + persist), clamped to full screen on phones.
 *
 * @param {String} mode window | dockright | openfull.
 */
const setMode = (mode) => {
    let effective = MODES[mode] ? mode : 'dockright';
    if ((effective === 'window' || effective === 'dockright') && window.innerWidth <= 576) {
        effective = 'openfull';
    }
    Object.values(MODES).forEach((cls) => panel.classList.remove(cls));
    panel.classList.add(MODES[effective]);
    activeMode = effective;
    writePrefs({mode});
    applySize();
    updateLayoutMenu();
};

/**
 * Open or close the layout menu.
 *
 * @param {Boolean} open Whether to show it.
 */
const openMenu = (open) => {
    const menu = panel.querySelector('[data-region="gai-layout-menu"]');
    const toggle = panel.querySelector('[data-action="gai-layout-toggle"]');
    if (!menu || !toggle) {
        return;
    }
    menu.hidden = !open;
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
};

/**
 * Begin a drag-resize from an edge handle. Width grows when the left edge is pulled left; height
 * grows when the top edge is pulled up (the panel is anchored to the right/bottom). The final size
 * is persisted so the panel reopens the way the user left it.
 *
 * @param {String} axis 'x' (width) or 'y' (height).
 * @param {PointerEvent} ev The pointerdown event.
 */
const startResize = (axis, ev) => {
    ev.preventDefault();
    const startX = ev.clientX;
    const startY = ev.clientY;
    const rect = panel.getBoundingClientRect();
    const startW = rect.width;
    const startH = rect.height;
    const onMove = (e) => {
        if (axis === 'x') {
            panel.style.width = clamp(startW + (startX - e.clientX), MIN_W, window.innerWidth * 0.96) + 'px';
        } else {
            panel.style.height = clamp(startH + (startY - e.clientY), MIN_H, window.innerHeight * 0.96) + 'px';
        }
    };
    const onUp = () => {
        document.removeEventListener('pointermove', onMove);
        document.removeEventListener('pointerup', onUp);
        document.body.style.userSelect = '';
        const r = panel.getBoundingClientRect();
        writePrefs(axis === 'x' ? {width: Math.round(r.width)} : {height: Math.round(r.height)});
    };
    document.body.style.userSelect = 'none';
    document.addEventListener('pointermove', onMove);
    document.addEventListener('pointerup', onUp);
};

/**
 * Show the popup.
 */
const show = () => {
    panel.classList.remove('gai-hidden');
    const launcher = document.querySelector('[data-gai-launch]');
    if (launcher) {
        launcher.setAttribute('aria-expanded', 'true');
    }
    const input = panel.querySelector('[data-region="gai-input"]');
    if (input) {
        input.focus();
    }
};

/**
 * Hide the popup and return focus to the launcher.
 */
const hide = () => {
    openMenu(false);
    panel.classList.add('gai-hidden');
    const launcher = document.querySelector('[data-gai-launch]');
    if (launcher) {
        launcher.setAttribute('aria-expanded', 'false');
        launcher.focus();
    }
};

/**
 * Build the popup once.
 *
 * @param {Object} cfg {contextid, courseid, cmid, pagetype, canupload, repoid, usercontextid, draftitemid, maxbytes}.
 */
const mount = async (cfg) => {
    const {html} = await Templates.renderForPromise('local_groundedai/popup', {
        title: await getString('staffassistant', 'local_groundedai'),
    });
    const holder = document.createElement('div');
    holder.innerHTML = html;
    panel = holder.firstElementChild;
    document.body.appendChild(panel);

    reactiveInit(panel.querySelector('[data-region="gai-appmount"]'), {
        contextid: cfg.contextid,
        lane: 'staff',
        courseid: cfg.courseid,
        cmid: cfg.cmid,
        pagetype: cfg.pagetype,
        canupload: cfg.canupload,
        repoid: cfg.repoid,
        usercontextid: cfg.usercontextid,
        draftitemid: cfg.draftitemid,
        maxbytes: cfg.maxbytes,
    });

    panel.querySelector('[data-action="gai-min"]').addEventListener('click', hide);
    panel.querySelector('[data-action="gai-close"]').addEventListener('click', hide);
    panel.querySelector('[data-action="gai-layout-toggle"]').addEventListener('click', (e) => {
        e.stopPropagation();
        const menu = panel.querySelector('[data-region="gai-layout-menu"]');
        openMenu(menu.hidden);
    });
    panel.querySelectorAll('[data-action="gai-mode"]').forEach((btn) => {
        btn.addEventListener('click', () => {
            setMode(btn.dataset.mode);
            openMenu(false);
        });
    });
    panel.querySelectorAll('.gai-resize').forEach((handle) => {
        handle.addEventListener('pointerdown', (e) => startResize(handle.dataset.resize, e));
    });
    document.addEventListener('click', (e) => {
        const menu = panel.querySelector('[data-region="gai-layout-menu"]');
        if (menu && !menu.hidden && !e.target.closest('[data-region="gai-layout"]')) {
            openMenu(false);
        }
    });
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape' || !panel || panel.classList.contains('gai-hidden')) {
            return;
        }
        const menu = panel.querySelector('[data-region="gai-layout-menu"]');
        if (menu && !menu.hidden) {
            openMenu(false);   // First Escape closes the menu, not the chat.
        } else {
            hide();
        }
    });
    setMode(readPrefs().mode || 'dockright');
};

/**
 * Wire the server-rendered staff launcher button to the popup.
 *
 * @param {Object} cfg {contextid, courseid, cmid, pagetype, canupload, repoid, usercontextid, draftitemid, maxbytes}.
 */
export const initStaff = (cfg) => {
    const launcher = document.querySelector('[data-gai-launch]');
    if (!launcher) {
        return;
    }
    launcher.addEventListener('click', async () => {
        // Guard re-entry: `panel` is only set after an await inside mount(), so a fast double-click could
        // otherwise build the popup (and auto-create a conversation) twice. Hold the in-flight mount.
        if (!panel && !mounting) {
            mounting = mount(cfg);
            try {
                await mounting;
            } finally {
                mounting = null;
            }
            show();
        } else if (mounting) {
            await mounting;
            show();
        } else if (panel.classList.contains('gai-hidden')) {
            show();
        } else {
            hide();
        }
    });
};
