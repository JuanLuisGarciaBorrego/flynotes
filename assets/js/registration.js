import { API, writeHeaders } from './api.js';
import { copyText } from './clipboard.js';
import { createStamp, flagUrl } from './stamp.js';

const FIELDS = ['name', 'countryCode'];
const NAME_MIN_LENGTH = 2;
// The real colour comes from the stamp's uuid, which does not exist yet: the preview shows one of the palette.
const PREVIEW_COLOR = '#0F766E';
const NO_COUNTRY_COLOR = '#94A3B8';

function show(el, text) {
    el.textContent = text; // textContent only: never inject server data as HTML
    el.classList.remove('hidden');
}

function clearErrors(form) {
    form.querySelector('#form-error').classList.add('hidden');
    for (const field of FIELDS) {
        const error = form.querySelector(`#${field}-error`);
        error.classList.add('hidden');
        error.textContent = '';
        form.elements[field].removeAttribute('aria-invalid');
    }
}

/** Maps API Platform JSON-LD / Hydra violations ({propertyPath, message}) to fields. */
function showViolations(form, violations) {
    let general = [];
    for (const { propertyPath, message } of violations) {
        if (FIELDS.includes(propertyPath)) {
            show(form.querySelector(`#${propertyPath}-error`), message);
            form.elements[propertyPath].setAttribute('aria-invalid', 'true');
        } else {
            general.push(message);
        }
    }
    if (general.length > 0) {
        show(form.querySelector('#form-error'), general.join(' '));
    }
}

function isComplete(form) {
    return [...form.elements.name.value.trim()].length >= NAME_MIN_LENGTH && form.elements.countryCode.value !== '';
}

function setLoading(form, loading) {
    const submit = form.querySelector('#submit');
    submit.disabled = loading || !isComplete(form);
    submit.setAttribute('aria-busy', String(loading));
    form.setAttribute('aria-busy', String(loading));
    form.querySelector('#spinner').classList.toggle('hidden', !loading);
    form.querySelector('#submit-label').textContent = loading ? 'Creating…' : 'Create my identity';
}

function setFlag(image, url) {
    image.hidden = url === null;
    if (url === null) {
        image.removeAttribute('src');
    } else {
        image.src = url;
    }
}

/** The ticket's stub and the submit button follow the form while typing, only on the client. */
function initPreview(root, form) {
    const name = root.querySelector('#preview-name');
    const stamp = root.querySelector('#preview-stamp');
    let shownCountry = null;

    const update = () => {
        const nickname = form.elements.name.value.trim();
        name.textContent = nickname || 'Your nickname';
        name.classList.toggle('text-[#94A3B8]', nickname === '');
        name.classList.toggle('text-ink', nickname !== '');

        const select = form.elements.countryCode;
        const code = select.value;
        if (code !== shownCountry) {
            shownCountry = code;
            const url = code ? flagUrl(code) : null;
            select.toggleAttribute('data-filled', url !== null);
            setFlag(root.querySelector('#country-flag'), url);
            setFlag(root.querySelector('#preview-flag'), url);
            root.querySelector('#preview-country').textContent = code ? select.selectedOptions[0].textContent : 'Your country';
            stamp.style.opacity = code ? '1' : '.55';
            stamp.replaceChildren(createStamp({ color: code ? PREVIEW_COLOR : NO_COUNTRY_COLOR, countryCode: code || null, size: 130 }));
        }

        form.querySelector('#submit').disabled = form.getAttribute('aria-busy') === 'true' || !isComplete(form);
    };

    form.addEventListener('input', update);
    form.addEventListener('change', update);
    window.addEventListener('pageshow', update); // a restored page keeps what was typed
    update();
}

function buildTxt({ name, code }) {
    return [
        'FlyNotes',
        '========',
        '',
        `Nickname: ${name}`,
        `Recovery code: ${code}`,
        '',
        'Keep this file somewhere safe.',
        '',
        'This code lets you recover your identity',
        'from another browser or device.',
        '',
        'Do not share this code with anyone.',
        '',
    ].join('\n');
}

function downloadCode(participant) {
    const blob = new Blob([buildTxt(participant)], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'flynotes-recovery-code.txt';
    document.body.append(link);
    link.click();
    link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}

/** The code's screen: same URL, no reload. `code` stays in this closure and on screen, nowhere else in the browser. */
function showCreated(participant, countryCode, countryName) {
    const section = document.querySelector('#identity-created');
    const $ = (selector) => section.querySelector(selector);
    const emoji = participant.emoji ?? '✈️';
    const { code } = participant;

    // Server data goes in with textContent only.
    $('#created-emoji').textContent = emoji;
    $('#created-title').textContent = `Welcome aboard, ${participant.name}`;
    $('#created-animal').textContent = `The ${emoji} is yours ·`;
    setFlag($('#created-flag'), flagUrl(countryCode));
    $('#created-country').textContent = countryName;
    $('#created-code').textContent = code;

    const status = $('#code-status');
    const announce = (text, visible = false) => {
        status.classList.toggle('sr-only', !visible);
        status.textContent = text;
    };

    $('#copy').addEventListener('click', async () => {
        try {
            await copyText(code, $('#created-code'));
            $('#copy').toggleAttribute('data-done', true);
            $('#copy-label').textContent = 'Copied';
            announce('Code copied');
        } catch {
            announce("Couldn't copy. Select the code and copy it manually.", true);
        }
    });
    $('#download').addEventListener('click', () => {
        downloadCode(participant);
        $('#download-label').textContent = 'Downloaded';
        announce('Code downloaded');
    });
    $('#code-saved').addEventListener('change', (event) => {
        $('#continue').disabled = !event.target.checked;
    });
    $('#continue').addEventListener('click', () => window.location.assign(section.dataset.continueUrl));

    // The code can't be shown again: nothing on this screen but the way forward.
    document.querySelector('[data-site-nav]').hidden = true;
    document.querySelector('[data-site-footer]').hidden = true;
    document.querySelector('[data-site-logo]').inert = true;
    document.querySelector('#registration').hidden = true;
    section.hidden = false;
    document.title = 'Your identity is ready · FlyNotes';
    window.scrollTo(0, 0);
    $('#created-title').focus({ preventScroll: true });
}

export function initRegistration() {
    const root = document.querySelector('#registration');
    const form = document.querySelector('#registration-form');
    if (!root || !form) {
        return;
    }
    let submitting = false;
    initPreview(root, form);

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (submitting || !isComplete(form)) {
            return;
        }
        // HTML validity is only a UX hint; the API remains the source of truth.
        const name = form.elements.name.value.trim();
        const countryCode = form.elements.countryCode.value;

        submitting = true;
        clearErrors(form);
        setLoading(form, true);

        try {
            const response = await fetch(API.participants, {
                method: 'POST',
                headers: writeHeaders(),
                credentials: 'same-origin',
                cache: 'no-store',
                body: JSON.stringify({ name, countryCode: countryCode || null }),
            });

            const data = await response.json().catch(() => null);

            if (response.status === 201 && data) {
                const country = form.elements.countryCode.selectedOptions[0];
                showCreated(data, country?.value, country?.textContent ?? '');
                return;
            }

            if (response.status === 422 && Array.isArray(data?.violations)) {
                showViolations(form, data.violations);
            } else if (response.status === 429) {
                show(form.querySelector('#form-error'), 'Too many attempts. Wait a moment and try again.');
            } else {
                show(form.querySelector('#form-error'), "We couldn't create your identity. Please try again.");
            }
        } catch {
            show(form.querySelector('#form-error'), "Can't reach the server. Check your connection and try again.");
        } finally {
            submitting = false;
            setLoading(form, false);
        }
    });
}
