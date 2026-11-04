import { stampApiUrl, planeApiUrl, writeHeaders, JSON_LD } from './api.js';
import { createStamp, countryName, flagEmoji, GUEST_COLOR } from './stamp.js';
import { publicAiView } from './ai-message.js';

// The real colour comes from the stamp's uuid, which only exists once it is stamped: the preview uses the accent.
const PREVIEW_COLOR = '#C2410C';
const MOBILE_QUERY = '(max-width: 639px)';
// Modal loader: only shown if the answer takes a noticeable time, and then never shorter than the minimum.
const LOADER_DELAY_MS = 200;
const LOADER_MIN_MS = 600;
export const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
let controller = null; // handlers of the previous plane (the dialog is reused)

/** Deterministic 32-bit hash (FNV-1a) so a stamp always lands in the same place. */
function hash(text) {
    let h = 0x811c9dc5;
    for (let i = 0; i < text.length; i++) {
        h = Math.imul(h ^ text.charCodeAt(i), 0x01000193);
    }
    return h >>> 0;
}

/** Hash in [min, max], with a different value per `salt`. */
const spread = (uuid, salt, min, max) => min + (hash(`${uuid}:${salt}`) % 1001) / 1000 * (max - min);

function relativeTime(iso) {
    const date = iso ? new Date(iso) : null;
    if (!date || Number.isNaN(date.getTime())) {
        return '';
    }
    const seconds = Math.round((date.getTime() - Date.now()) / 1000);
    const formatter = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });
    for (const [unit, size] of [['day', 86400], ['hour', 3600], ['minute', 60]]) {
        if (Math.abs(seconds) >= size) {
            return formatter.format(Math.trunc(seconds / size), unit);
        }
    }
    return 'just now';
}

/** Stamps of the plane like a passport page: previous ones overlapped and tilted, mine on top. */
function paintStamps(zone, stamps, { showGhost }) {
    const mobile = window.matchMedia(MOBILE_QUERY).matches;
    const size = mobile ? 92 : 116;
    const mineSize = mobile ? 110 : 140;
    const baseHeight = mobile ? 220 : 250;
    const cols = mobile ? 3 : 4;
    const width = zone.clientWidth || 320;
    const rowStep = Math.round(size * 0.82);
    const others = stamps.filter((stamp) => !stamp.mine);
    const mine = stamps.find((stamp) => stamp.mine);
    const nodes = [];

    others.forEach((stamp, i) => {
        const col = i % cols;
        const row = Math.floor(i / cols);
        const node = document.createElement('div');
        node.className = 'passport-stamp';
        node.style.left = `${Math.round(col * ((width - size) / (cols - 1)) + spread(stamp.uuid, 'x', -6, 6))}px`;
        node.style.top = `${Math.round(row * rowStep + (col % 2) * 22 + spread(stamp.uuid, 'y', 0, 14))}px`;
        node.style.transform = `rotate(${spread(stamp.uuid, 'r', -14, 14).toFixed(1)}deg)`;
        node.style.zIndex = '1';
        node.append(createStamp({ color: stamp.color, countryCode: stamp.countryCode, size }));
        nodes.push(node);
    });

    const left = `${mobile ? 33 : 39}%`;
    const top = mobile ? 52 : 56;
    if (mine) {
        const node = document.createElement('div');
        node.className = 'passport-stamp';
        node.style.cssText = `left:${left};top:${top}px;transform:rotate(-7deg);z-index:5`;
        const inner = document.createElement('div');
        inner.className = mine.fresh ? 'stamp-in' : '';
        mine.fresh = false; // the stamp-in animation plays once; later repaints (resize) must not replay it
        inner.append(createStamp({ color: mine.color, countryCode: mine.countryCode, size: mineSize }));
        node.append(inner);
        nodes.push(node);
    } else if (showGhost) {
        const ghost = document.createElement('div');
        ghost.className = 'passport-ghost';
        ghost.style.cssText = `left:${left};top:${top}px;width:${mobile ? 104 : 132}px;height:${mobile ? 104 : 132}px;z-index:5`;
        ghost.textContent = 'Your stamp goes here';
        nodes.push(ghost);
    }

    const rows = Math.ceil(others.length / cols);
    zone.style.height = `${Math.max(baseHeight, rows * rowStep + size + 24)}px`;
    zone.replaceChildren(...nodes);
}

/**
 * Fills the (already open) dialog with a plane from GET /api/paper_planes/{uuid}.
 */
export function renderPlane(dialog, plane) {
    controller?.abort();
    controller = new AbortController();
    const { signal } = controller;
    const $ = (selector) => dialog.querySelector(selector);

    const stamps = Array.isArray(plane.stamps) ? [...plane.stamps] : [];
    const zone = $('#plane-stamps');
    const error = $('#stamp-error');
    const button = $('[data-stamp-button]');
    const label = $('[data-stamp-button-label]');

    // Server data goes in with textContent only.
    $('#plane-message').textContent = plane.message ?? '';
    $('#plane-body').classList.toggle('plane-report', plane.isSkyPulseReport === true);
    $('#plane-report-badge').hidden = plane.isSkyPulseReport !== true;
    const aiView = publicAiView(plane);
    $('#plane-ai').classList.toggle('hidden', aiView === null);
    $('#plane-ai').classList.toggle('flex', aiView !== null);
    $('#plane-ai-label').textContent = aiView?.label ?? '';
    $('#plane-ai-text').textContent = aiView?.text ?? '';
    // A report shows the robot, not the agent's stored emoji. The dialog is reused, so both are set for every plane.
    $('#plane-author-emoji').textContent = plane.isSkyPulseReport === true ? '' : plane.authorEmoji ?? '';
    $('#plane-author-robot').classList.toggle('hidden', plane.isSkyPulseReport !== true);
    $('#plane-author-name').textContent = plane.authorName ?? '';
    const fromFlag = flagEmoji(plane.authorCountryCode);
    const when = relativeTime(plane.createdAt);
    const origin = fromFlag ? `Launched from ${fromFlag} ${countryName(plane.authorCountryCode)}` : 'Launched';
    $('#plane-author-origin').textContent = when ? `${origin} · ${when}` : origin;

    const viewerCountry = `${flagEmoji(plane.viewerCountryCode) ?? ''} ${plane.viewerCountryName ?? countryName(plane.viewerCountryCode)}`.trim();
    for (const node of dialog.querySelectorAll('[data-viewer-country]')) {
        node.textContent = viewerCountry;
    }

    let state;
    if (plane.hasStamped || plane.stampedByMe) {
        state = 'stamped';
    } else if (plane.isAuthor) {
        state = 'author';
    } else if (plane.canStamp) {
        state = 'can-stamp';
    } else if (!plane.isAuthenticated) {
        state = 'anonymous';
    } else {
        state = 'none';
    }

    const views = Number.isInteger(plane.viewCount) ? plane.viewCount : 0;
    $('#plane-view-count').textContent = `${views} ${views === 1 ? 'view' : 'views'}`;

    const paint = () => {
        const count = stamps.length;
        $('#plane-stamp-count').textContent = `${count} ${count === 1 ? 'country' : 'countries'}`;
        paintStamps(zone, stamps, { showGhost: state === 'can-stamp' });
    };
    const setState = (next) => {
        state = next;
        // The side column only exists to stamp: hidden on the viewer's own plane (and when there is nothing to offer).
        const noPanel = state === 'author' || state === 'none';
        $('#plane-panel').classList.toggle('hidden', noPanel);
        $('#plane-panel').classList.toggle('flex', !noPanel);
        for (const panel of dialog.querySelectorAll('[data-stamp-state]')) {
            panel.classList.toggle('hidden', panel.dataset.stampState !== state);
        }
        const preview = $('[data-stamp-preview]');
        const mine = stamps.find((stamp) => stamp.mine);
        preview.replaceChildren(createStamp({ color: mine?.color ?? PREVIEW_COLOR, countryCode: plane.viewerCountryCode }));
    };
    const showError = (text) => {
        error.textContent = text;
        error.classList.remove('hidden');
    };

    error.classList.add('hidden');
    $('[data-stamp-preview-guest]').replaceChildren(createStamp({ color: GUEST_COLOR }));

    // Return to this very plane after registering or recovering the identity (the server only accepts a UUID).
    for (const link of dialog.querySelectorAll('[data-base-url]')) {
        link.href = `${link.dataset.baseUrl}?plane=${encodeURIComponent(plane.uuid)}`;
    }

    setState(state);
    paint();
    // Debounced: dragging the window fires resize continuously, and each repaint rebuilds the stamps.
    let resizeTimer;
    window.addEventListener('resize', () => {
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(paint, 150);
    }, { signal });
    signal.addEventListener('abort', () => window.clearTimeout(resizeTimer));

    label.textContent = 'Add my stamp';
    button.disabled = false;
    if (state !== 'can-stamp') {
        return;
    }

    let stamping = false;
    button.addEventListener('click', async () => {
        if (stamping) {
            return;
        }
        stamping = true;
        button.disabled = true;
        label.textContent = 'Stamping…';
        error.classList.add('hidden');

        try {
            // No body: the server takes participant and country from the session.
            const response = await fetch(stampApiUrl(plane.uuid), {
                method: 'POST',
                headers: writeHeaders(),
                credentials: 'same-origin',
                cache: 'no-store',
            });
            if (signal.aborted) {
                return;
            }

            if (response.status === 201) {
                const stamp = await response.json();
                stamps.push({ uuid: stamp.stampUuid, color: stamp.color, countryCode: stamp.countryCode, mine: true, fresh: true });
                setState('stamped');
                paint();
            } else if (response.status === 409) {
                const fresh = await fetch(planeApiUrl(plane.uuid), JSON_LD).then((r) => (r.ok ? r.json() : null)).catch(() => null);
                if (signal.aborted) {
                    return;
                }
                if (fresh !== null) {
                    renderPlane(dialog, { ...fresh, hasStamped: true });
                } else {
                    setState('stamped');
                }
            } else if (response.status === 401) {
                setState('anonymous');
                showError('Your identity has expired. Sign in again to stamp.');
            } else if (response.status === 403) {
                setState('none');
                showError("You can't stamp this plane.");
            } else if (response.status === 404) {
                setState('none');
                showError('This plane no longer exists.');
            } else {
                showError("We couldn't add your stamp. Please try again.");
            }
        } catch {
            showError("Can't reach the server. Check your connection and try again.");
        } finally {
            stamping = false;
            button.disabled = false;
            label.textContent = 'Add my stamp';
        }
    }, { signal });
}

/**
 * Wires the shared #plane-dialog (templates/paper_plane/_dialog.html.twig) and returns `openPlane(uuid, trigger)`.
 * A plane is read in a modal: it has no page (or URL) of its own.
 */
export function initPlaneDialog(dialog) {
    let requestId = 0; // identifies the latest request, so a stale answer is never painted
    let opener = null; // plane button that opened the modal, to give it the focus back on close

    const loading = dialog.querySelector('#plane-loading');
    const lost = dialog.querySelector('#plane-lost');
    const body = dialog.querySelector('#plane-body');
    const wait = (ms) => new Promise((resolve) => window.setTimeout(resolve, ms));

    async function openPlane(uuid, trigger = null) {
        if (dialog.open) {
            return; // double click / concurrent open: the modal is already showing a plane
        }
        const id = ++requestId;
        opener = trigger;
        const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        body.classList.add('hidden');
        lost.classList.add('hidden');
        loading.classList.remove('hidden');
        dialog.showModal();

        const shownAt = performance.now();
        let plane = null;
        try {
            const response = await fetch(planeApiUrl(uuid), JSON_LD);
            if (!response.ok) {
                throw new Error(String(response.status));
            }
            plane = await response.json();
        } catch {
            plane = null;
        }

        // Avoid a flash of the loader: if it was visible, keep it a minimum time.
        const elapsed = performance.now() - shownAt;
        if (elapsed > LOADER_DELAY_MS && elapsed < LOADER_MIN_MS && !reduceMotion) {
            await wait(LOADER_MIN_MS - elapsed);
        }
        if (id !== requestId) {
            return; // the modal was closed or another plane opened meanwhile
        }
        loading.classList.add('hidden');
        if (plane === null) {
            lost.classList.remove('hidden');
            return;
        }
        body.classList.remove('hidden');
        body.scrollTop = 0;
        renderPlane(dialog, plane);
    }

    dialog.addEventListener('close', () => {
        requestId++;
        opener?.focus?.({ preventScroll: true });
        opener = null;
    });

    for (const closer of dialog.querySelectorAll('[data-plane-close]')) {
        closer.addEventListener('click', () => dialog.close());
    }
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) { // click on the backdrop
            dialog.close();
        }
    });

    return openPlane;
}
