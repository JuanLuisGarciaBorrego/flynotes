import { API, writeHeaders } from './api.js';
import { copyText } from './clipboard.js';

const FIELDS = ['name', 'countryCode'];

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

function setLoading(form, loading) {
    form.querySelector('#submit').disabled = loading;
    form.querySelector('#spinner').classList.toggle('hidden', !loading);
    form.querySelector('#submit-label').textContent = loading ? 'Creating…' : 'Create my identity';
    form.setAttribute('aria-busy', String(loading));
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
    URL.revokeObjectURL(url);
}

async function copyCode(code, status) {
    try {
        await copyText(code, document.querySelector('#done-code'));
        status.textContent = 'Code copied';
    } catch {
        status.textContent = "Couldn't copy. Select the code and copy it manually.";
    }
}

function showDone(root, participant, countryName) {
    root.querySelector('#registration-header').classList.add('hidden');
    root.querySelector('#registration-form').classList.add('hidden');
    root.querySelector('#recovery-link').classList.add('hidden');
    root.querySelector('#done-emoji').textContent = participant.emoji ?? '✈️';
    root.querySelector('#done-name').textContent = participant.name;
    root.querySelector('#done-country').textContent = countryName;
    root.querySelector('#done-code').textContent = participant.code;
    root.querySelector('#registration-done').classList.remove('hidden');

    const status = root.querySelector('#copy-status');
    root.querySelector('#copy').addEventListener('click', () => copyCode(participant.code, status));
    root.querySelector('#download').addEventListener('click', () => downloadCode(participant));
    root.querySelector('#copy').focus();
}

export function initRegistration() {
    const root = document.querySelector('#registration');
    const form = document.querySelector('#registration-form');
    if (!root || !form) {
        return;
    }
    let submitting = false;

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (submitting) {
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
                const option = form.elements.countryCode.selectedOptions[0];
                const country = option?.dataset.name ? `${option.dataset.flag} ${option.dataset.name}` : '';
                showDone(root, data, country);
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
