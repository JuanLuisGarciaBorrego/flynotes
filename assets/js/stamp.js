// "Hello World!" stamp: a round sticker with a peeled corner. Pure DOM, server data only via attributes/textContent.
const SVG_NS = 'http://www.w3.org/2000/svg';
export const GUEST_COLOR = '#64748B';

let gradientCount = 0;

function svgEl(name, attrs) {
    const node = document.createElementNS(SVG_NS, name);
    for (const [key, value] of Object.entries(attrs)) {
        node.setAttribute(key, value);
    }
    return node;
}

/** Regional indicator symbols, e.g. "ES" => 🇪🇸 (same rule as the registration select). Null if not a 2-letter code. */
export function flagEmoji(code) {
    if (typeof code !== 'string' || !/^[A-Za-z]{2}$/.test(code)) {
        return null;
    }
    return [...code.toUpperCase()].map((c) => String.fromCodePoint(0x1f1e6 + c.charCodeAt(0) - 65)).join('');
}

const regionNames = typeof Intl.DisplayNames === 'function' ? new Intl.DisplayNames(['en'], { type: 'region' }) : null;

/** English country name for an ISO code, or the code itself if unknown. */
export function countryName(code) {
    if (typeof code !== 'string' || !/^[A-Za-z]{2}$/.test(code)) {
        return '';
    }
    try {
        return regionNames?.of(code.toUpperCase()) ?? code.toUpperCase();
    } catch {
        return code.toUpperCase();
    }
}

/**
 * @param {{color: string, countryCode?: string|null, size?: number|null}} options
 *   `size` in px; without it the stamp takes `--stamp-size` from its container (responsive previews).
 *   Without a valid `countryCode` there is no flag (visitors without a session).
 */
export function createStamp({ color, countryCode = null, size = null }) {
    const gradientId = `stamp-flap-${++gradientCount}`;
    const flag = flagEmoji(countryCode);
    const name = countryName(countryCode);

    const root = document.createElement('div');
    root.className = 'stamp';
    root.setAttribute('role', 'img');
    root.setAttribute('aria-label', `Hello World! stamp from ${name || 'your country'}`);
    if (size !== null) {
        root.style.setProperty('--stamp-size', `${size}px`);
    }

    const body = 'M 8.25 93.25 A 92 92 0 1 1 106.75 191.75 Z';
    const flap = 'M 8.25 93.25 A 92 92 0 0 1 106.75 191.75 Z';
    const svg = svgEl('svg', { viewBox: '0 0 200 200', 'aria-hidden': 'true' });
    const gradient = svgEl('linearGradient', { id: gradientId, x1: '0', y1: '0', x2: '1', y2: '1' });
    gradient.append(svgEl('stop', { offset: '0', 'stop-color': '#FFFFFF' }), svgEl('stop', { offset: '1', 'stop-color': '#E2DCD2' }));
    svg.append(svgEl('defs', {}));
    svg.firstChild.append(gradient);

    const text = svgEl('g', { transform: 'rotate(-10 112 100)', 'text-anchor': 'middle', 'font-family': 'Pacifico, cursive' });
    for (const [x, y, fontSize, fill, label] of [
        [118, 89, 40, 'rgba(0,0,0,0.28)', 'Hello'],
        [116, 86, 40, '#FFFFFF', 'Hello'],
        [120, 129, 34, 'rgba(0,0,0,0.28)', 'World!'],
        [118, 126, 34, '#FFFFFF', 'World!'],
    ]) {
        const node = svgEl('text', { x, y, 'font-size': fontSize, fill });
        node.textContent = label;
        text.append(node);
    }

    svg.append(
        svgEl('path', { d: body, fill: 'rgba(20,33,61,0.18)', transform: 'translate(2 4)' }),
        svgEl('path', { d: body, fill: color }),
        svgEl('path', { d: 'M 20.2 94.1 A 80 80 0 1 1 105.9 179.8', fill: 'none', stroke: '#FFFFFF', 'stroke-opacity': '0.45', 'stroke-width': '2', 'stroke-dasharray': '2 6', 'stroke-linecap': 'round' }),
        text,
        svgEl('path', { d: flap, fill: 'rgba(20,33,61,0.22)', transform: 'translate(4 -3)' }),
        svgEl('path', { d: flap, fill: `url(#${gradientId})`, stroke: 'rgba(20,33,61,0.15)', 'stroke-width': '1' }),
    );
    root.append(svg);

    if (flag !== null) {
        const flagNode = document.createElement('span');
        flagNode.className = 'stamp-flag';
        flagNode.setAttribute('aria-hidden', 'true'); // the stamp's aria-label already names the country
        flagNode.textContent = flag;
        root.append(flagNode);
    }

    return root;
}
