import { API, writeHeaders } from './api.js';

const MESSAGES = {
    400: "That code isn't valid. Check that you typed it correctly.",
    429: 'Too many attempts. Wait a minute and try again.',
};

function setLoading(form, loading) {
    form.querySelector('#submit').disabled = loading;
    form.querySelector('#spinner').classList.toggle('hidden', !loading);
    form.querySelector('#submit-label').textContent = loading ? 'Recovering…' : 'Recover my identity';
    form.setAttribute('aria-busy', String(loading));
}

export function initRecovery() {
    const root = document.querySelector('#recovery');
    const form = document.querySelector('#recovery-form');
    if (!root || !form) {
        return;
    }
    const error = form.querySelector('#form-error');
    let submitting = false;

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (submitting) {
            return;
        }
        const code = form.elements.code.value.trim();
        if (code === '') {
            error.textContent = 'Enter your recovery code.';
            error.classList.remove('hidden');
            return;
        }

        submitting = true;
        error.classList.add('hidden');
        setLoading(form, true);

        try {
            // The code only travels in this request body: it is never stored client-side.
            const response = await fetch(API.recover, {
                method: 'POST',
                headers: writeHeaders(),
                credentials: 'same-origin',
                cache: 'no-store',
                body: JSON.stringify({ code }),
            });
            const data = await response.json().catch(() => null);

            if (response.ok && data) {
                form.elements.code.value = '';
                root.querySelector('#recovery-form-section').classList.add('hidden');
                root.querySelector('#done-emoji').textContent = data.emoji ?? '✈️';
                root.querySelector('#done-name').textContent = data.name; // textContent: never HTML
                root.querySelector('#recovery-done').classList.remove('hidden');
                root.querySelector('#continue').focus();
                return;
            }

            error.textContent = MESSAGES[response.status] ?? "We couldn't recover your identity. Please try again.";
            error.classList.remove('hidden');
        } catch {
            error.textContent = "Can't reach the server. Check your connection and try again.";
            error.classList.remove('hidden');
        } finally {
            submitting = false;
            setLoading(form, false);
        }
    });
}
