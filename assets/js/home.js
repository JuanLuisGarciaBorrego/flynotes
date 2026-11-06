import { API, SENT_KEY } from './api.js';
import { initPlaneDialog, UUID_PATTERN } from './plane-modal.js';

const RESIZE_DEBOUNCE_MS = 200;
const MOBILE_QUERY = '(max-width: 639px)';

function showSentToast() {
    const message = sessionStorage.getItem(SENT_KEY);
    sessionStorage.removeItem(SENT_KEY); // shown once
    const toast = document.querySelector('#sent-toast');
    if (message === null || !toast) {
        return;
    }
    toast.querySelector('#sent-toast-message').textContent = message;
    toast.classList.remove('hidden');
    toast.querySelector('#sent-toast-close').addEventListener('click', () => toast.classList.add('hidden'));
    window.setTimeout(() => toast.classList.add('hidden'), 8000);
}

const rand = (min, max) => min + Math.random() * (max - min);
const SVG_NS = 'http://www.w3.org/2000/svg';
// On a phone planes fly between the sky's top bar and the dock, with this much air around them.
const MOBILE_FLIGHT_MARGIN = 24;
const MOBILE_MIN_BAND = 80;

function svgEl(name, attrs) {
    const el = document.createElementNS(SVG_NS, name);
    for (const [key, value] of Object.entries(attrs)) {
        el.setAttribute(key, value);
    }
    return el;
}

/** Paper plane with its dotted trail, pointing right. */
function paperPlane() {
    const svg = svgEl('svg', { viewBox: '0 0 160 48', fill: 'none', 'stroke-linejoin': 'round', 'aria-hidden': 'true' });
    svg.append(
        svgEl('path', { d: 'M2 30 C 30 36, 60 22, 92 26', stroke: '#8FA3BA', 'stroke-width': '1.5', 'stroke-dasharray': '4 6', 'stroke-linecap': 'round' }),
        svgEl('path', { class: 'plane-wing', d: 'M98 20 L158 4 L118 30 Z', fill: '#FFFFFF', stroke: '#14213D', 'stroke-width': '1.6' }),
        svgEl('path', { class: 'plane-fold', d: 'M118 30 L158 4 L126 44 Z', fill: '#D3DEE9', stroke: '#14213D', 'stroke-width': '1.6' }),
        svgEl('path', { d: 'M118 30 L121 40 L126 44', stroke: '#14213D', 'stroke-width': '1.6' }),
    );
    return svg;
}

/** Sky Pulse's violet plane: flies with the others, but it is a link to its page, in front of the rest. */
function skyPulsePlane(url) {
    const el = document.createElement('a');
    el.href = url;
    el.className = 'plane plane-pulse';
    el.setAttribute('aria-label', 'Sky Pulse: follow the research missions of the AI agent');
    el.append(paperPlane());
    return { el, depth: 1 };
}

/** Closed smooth loop through 5 random points (Catmull-Rom converted to cubic Béziers). */
function randomLoop(width, { minY, maxY }, mobile) {
    const padX = mobile ? 30 : 60;
    const pts = Array.from({ length: 5 }, () => [
        Math.round(padX + rand(0, Math.max(80, width - padX * 2))),
        Math.round(rand(minY, maxY)),
    ]);
    const n = pts.length;
    let d = `M ${pts[0][0]} ${pts[0][1]}`;
    for (let i = 0; i < n; i++) {
        const p0 = pts[(i - 1 + n) % n];
        const p1 = pts[i];
        const p2 = pts[(i + 1) % n];
        const p3 = pts[(i + 2) % n];
        const c1 = [Math.round(p1[0] + (p2[0] - p0[0]) / 6), Math.round(p1[1] + (p2[1] - p0[1]) / 6)];
        const c2 = [Math.round(p2[0] - (p3[0] - p1[0]) / 6), Math.round(p2[1] - (p3[1] - p1[1]) / 6)];
        d += ` C ${c1[0]} ${c1[1]} ${c2[0]} ${c2[1]} ${p2[0]} ${p2[1]}`;
    }
    return `${d} Z`;
}

export function initHome() {
    const sky = document.querySelector('#planes');
    if (!sky) {
        return;
    }
    const empty = document.querySelector('#feed-empty');
    const error = document.querySelector('#feed-error');
    const dialog = document.querySelector('#plane-dialog');
    /** @type {{el: HTMLButtonElement, depth: number}[]} */
    let planes = [];

    const openPlane = initPlaneDialog(dialog);

    /** Vertical band the planes fly in, in the sky's coordinates. */
    function flightBand(mobile) {
        if (!mobile) {
            const minY = 90;
            return { minY, maxY: Math.max(minY + 80, sky.clientHeight - 50) };
        }
        const skyTop = sky.getBoundingClientRect().top;
        const minY = Math.round(document.querySelector('#sky-bar').getBoundingClientRect().bottom - skyTop + MOBILE_FLIGHT_MARGIN);
        const dockTop = document.querySelector('#dock').getBoundingClientRect().top - skyTop;
        return { minY, maxY: Math.max(minY + MOBILE_MIN_BAND, Math.round(dockTop - MOBILE_FLIGHT_MARGIN)) };
    }

    /** Sets path, size, opacity, z-index and timing of every plane from the current sky size. */
    function layout() {
        const width = sky.clientWidth;
        const mobile = window.matchMedia(MOBILE_QUERY).matches;
        const band = flightBand(mobile);
        for (const { el, depth } of planes) {
            const size = mobile ? 38 + depth * 82 : 52 + depth * 128;
            const duration = (mobile ? 40 - depth * 16 : 70 - depth * 30) * rand(0.8, 1.2);
            el.style.width = `${Math.round(size)}px`;
            el.style.opacity = String(Math.round((0.32 + depth * 0.68) * 100) / 100);
            el.style.zIndex = String(Math.round(depth * 10));
            el.style.offsetPath = `path('${randomLoop(width, band, mobile)}')`;
            el.style.animationDuration = `${duration.toFixed(1)}s`;
            el.style.animationDelay = `${(-rand(0, duration)).toFixed(1)}s`;
        }
    }

    async function loadSky() {
        empty.classList.add('hidden');
        error.classList.add('hidden');
        try {
            const response = await fetch(API.paperPlanes, { headers: { Accept: 'application/ld+json' } });
            if (!response.ok) {
                throw new Error(String(response.status));
            }
            const data = await response.json();
            const members = data.member ?? data['hydra:member'] ?? [];
            empty.classList.toggle('hidden', members.length > 0);

            planes = members.map(({ uuid, isSkyPulseReport }) => {
                const el = document.createElement('button');
                el.type = 'button';
                el.className = isSkyPulseReport ? 'plane plane-report' : 'plane';
                el.addEventListener('click', () => openPlane(uuid, el));
                el.setAttribute('aria-label', isSkyPulseReport ? 'Open Sky Pulse report' : 'Open paper plane');
                el.append(paperPlane());
                return { el, depth: Math.random() };
            });
            planes.push(skyPulsePlane(sky.dataset.skyPulseUrl));
            sky.replaceChildren(...planes.map(({ el }) => el));
            layout();
        } catch {
            error.classList.remove('hidden');
        }
    }

    let resizeTimer;
    window.addEventListener('resize', () => {
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(layout, RESIZE_DEBOUNCE_MS);
    });

    const dock = document.querySelector('#dock');
    const dockToggle = document.querySelector('#dock-toggle');
    dockToggle.addEventListener('click', () => {
        const expanded = dock.toggleAttribute('data-expanded');
        dockToggle.setAttribute('aria-expanded', String(expanded));
        document.querySelector('#dock-toggle-label').textContent = expanded ? 'Less' : 'More';
        layout(); // the dock has just changed height, and the planes fly above it
    });

    document.querySelector('#reload').addEventListener('click', loadSky);
    showSentToast();
    loadSky();

    // Coming back from registering/recovering an identity: reopen that plane (only a well-formed UUID is accepted).
    const params = new URLSearchParams(window.location.search);
    const returnPlane = params.get('plane');
    if (returnPlane !== null) {
        window.history.replaceState(null, '', window.location.pathname);
        if (UUID_PATTERN.test(returnPlane)) {
            openPlane(returnPlane);
        }
    }
}
