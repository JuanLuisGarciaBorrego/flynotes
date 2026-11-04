import { API, JSON_LD } from './api.js';
import { initPlaneDialog } from './plane-modal.js';
import { createStamp } from './stamp.js';

const MINI_STAMP_SIZE = 46;
const MINI_STAMPS_SHOWN = 4;
// Mini stamps of a card: overlapped and slightly tilted, like a passport page.
const MINI_STAMP_TILTS = [-9, 7, -4, 10];
const STATS = ['totalPlanes', 'totalViews', 'totalCountries'];

const DAY_FORMAT = new Intl.DateTimeFormat('en', { day: 'numeric', month: 'short' });
const TIME_FORMAT = new Intl.DateTimeFormat('en', { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });

/** An API answer that is not a 2xx: `status` is 0 for network errors. */
class LoadError extends Error {
    constructor(status) {
        super(`HTTP ${status}`);
        this.status = status;
    }
}

async function getJson(url) {
    let response;
    try {
        response = await fetch(url, JSON_LD);
    } catch {
        throw new LoadError(0);
    }
    if (!response.ok) {
        throw new LoadError(response.status);
    }
    return response.json();
}

/** Page number of a Hydra view link such as "/api/me/paper_planes?page=2" (1 when it has none). */
function pageOf(link) {
    const page = Number(new URL(link, window.location.origin).searchParams.get('page'));
    return Number.isInteger(page) && page > 0 ? page : 1;
}

const plural = (count, one, many) => `${count} ${count === 1 ? one : many}`;

/**
 * "My planes": a page shell (templates/paper_plane/my_planes.html.twig) filled from the API.
 * The totals are loaded once; the planes, a page of GET /api/me/paper_planes at a time (the server
 * fixes the page size). The current page is kept in ?page= so a reload stays on it.
 */
export function initMyPlanes() {
    const page = document.querySelector('#my-planes-page');
    if (!page) {
        return;
    }

    const loading = page.querySelector('#my-planes-loading');
    const error = page.querySelector('#my-planes-error');
    const errorMessage = error.querySelector('[data-error-message]');
    const retry = error.querySelector('[data-retry]');
    const empty = page.querySelector('#my-planes-empty');
    const summary = page.querySelector('#my-planes-summary');
    const list = page.querySelector('#my-planes');
    const pagination = page.querySelector('#my-planes-pagination');
    const pageLabel = pagination.querySelector('[data-page-label]');
    const previous = pagination.querySelector('[data-page="previous"]');
    const next = pagination.querySelector('[data-page="next"]');
    const cardTemplate = page.querySelector('#my-plane-card');
    const withPlanes = page.querySelectorAll('[data-when-planes]');
    const openPlane = initPlaneDialog(page.querySelector('#plane-dialog'));

    let requestId = 0; // only the latest page request may paint
    let statsLoaded = false;
    let links = { previous: null, next: null };

    function homeLinkFor(uuid) {
        const url = new URL(page.dataset.homeUrl, window.location.origin);
        url.searchParams.set('plane', uuid);
        return `${url.pathname}${url.search}`;
    }

    function buildCard(plane) {
        const card = cardTemplate.content.firstElementChild.cloneNode(true);
        card.dataset.planeUuid = plane.uuid;

        card.querySelector(`[data-status="${plane.viewCount > 0 ? 'waiting' : 'flying'}"]`).remove();

        const time = card.querySelector('time');
        const createdAt = new Date(plane.createdAt);
        time.dateTime = plane.createdAt;
        time.textContent = `Launched ${DAY_FORMAT.format(createdAt)} · ${TIME_FORMAT.format(createdAt)}`;

        card.querySelector('[data-message]').textContent = plane.message;
        card.querySelector('[data-views] [data-count]').textContent = plural(plane.viewCount, 'view', 'views');
        card.querySelector('[data-stamps] [data-count]').textContent = plural(plane.stampCount, 'stamp', 'stamps');

        const miniStamps = card.querySelector('[data-mini-stamps]');
        if (plane.stamps.length === 0) {
            miniStamps.remove();
        } else {
            card.querySelector('[data-no-stamps]').remove();
            plane.stamps.slice(-MINI_STAMPS_SHOWN).forEach((stamp, i) => {
                const slot = document.createElement('li');
                slot.className = '-ml-4 size-[46px] shrink-0';
                slot.style.transform = `rotate(${MINI_STAMP_TILTS[i]}deg)`;
                slot.append(createStamp({ color: stamp.color, countryCode: stamp.countryCode || null, size: MINI_STAMP_SIZE }));
                miniStamps.append(slot);
            });
        }

        const link = card.querySelector('[data-open-plane]');
        link.href = homeLinkFor(plane.uuid);
        link.dataset.openPlane = plane.uuid;

        return card;
    }

    function showOnly(state) {
        loading.hidden = state !== 'loading';
        error.hidden = state !== 'error';
        empty.hidden = state !== 'empty';
        for (const element of withPlanes) {
            element.hidden = state !== 'planes';
        }
        if (state !== 'planes') {
            pagination.hidden = true;
        }
    }

    function fail(status) {
        if (status === 401) {
            window.location.assign(page.dataset.registrationUrl); // the session expired: same as the page itself
            return;
        }
        errorMessage.textContent = status === 403
            ? 'Only registered participants can see their planes.'
            : "We couldn't load your planes.";
        retry.hidden = status === 403;
        showOnly('error');
    }

    async function loadStats() {
        try {
            const stats = await getJson(API.myPaperPlaneStats);
            for (const name of STATS) {
                summary.querySelector(`[data-stat="${name}"]`).textContent = String(stats[name]);
            }
            statsLoaded = true;
        } catch (exception) {
            if (exception.status === 401) {
                fail(401);
            }
            // Otherwise the summary keeps its "—": the list is still useful without the totals.
        } finally {
            summary.removeAttribute('aria-busy');
        }
    }

    async function loadPage(pageNumber, { focus = false } = {}) {
        const id = ++requestId;
        list.setAttribute('aria-busy', 'true');
        previous.disabled = true;
        next.disabled = true;

        let collection;
        try {
            const url = pageNumber > 1 ? `${API.myPaperPlanes}?page=${pageNumber}` : API.myPaperPlanes;
            collection = await getJson(url);
        } catch (exception) {
            if (id === requestId) {
                fail(exception.status);
            }
            return;
        } finally {
            if (id === requestId) {
                list.removeAttribute('aria-busy');
            }
        }
        if (id !== requestId) {
            return; // a newer page was requested meanwhile
        }

        const planes = collection.member ?? [];
        if (planes.length === 0 && pageNumber > 1 && collection.totalItems > 0) {
            loadPage(1, { focus }); // a stale ?page= beyond the last one
            return;
        }
        if (planes.length === 0) {
            showOnly('empty');
            window.history.replaceState(null, '', window.location.pathname);
            return;
        }

        list.replaceChildren(...planes.map(buildCard));
        showOnly('planes');

        const view = collection.view ?? {};
        const current = view['@id'] ? pageOf(view['@id']) : 1;
        links = { previous: view.previous ?? null, next: view.next ?? null };
        pagination.hidden = !links.previous && !links.next;
        previous.disabled = !links.previous;
        next.disabled = !links.next;
        pageLabel.textContent = `Page ${current} of ${view.last ? pageOf(view.last) : current}`;

        const url = new URL(window.location.href);
        if (current > 1) {
            url.searchParams.set('page', String(current));
        } else {
            url.searchParams.delete('page');
        }
        window.history.replaceState(null, '', `${url.pathname}${url.search}`);

        if (focus) {
            list.focus({ preventScroll: true });
            list.scrollIntoView({ block: 'start', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
        }
    }

    previous.addEventListener('click', () => links.previous && loadPage(pageOf(links.previous), { focus: true }));
    next.addEventListener('click', () => links.next && loadPage(pageOf(links.next), { focus: true }));
    retry.addEventListener('click', () => {
        showOnly('loading');
        if (!statsLoaded) {
            loadStats();
        }
        loadPage(pageOf(window.location.href));
    });

    // Event delegation: the cards change with every page.
    list.addEventListener('click', (event) => {
        const link = event.target.closest('[data-open-plane]');
        if (link) {
            event.preventDefault();
            openPlane(link.dataset.openPlane, link);
        }
    });

    // Both requests at once; changing page later never reloads the totals.
    loadStats();
    loadPage(pageOf(window.location.href));
}
