import { API, writeHeaders } from './api.js';

const CODE_LENGTH = 16;
const GROUP_LENGTH = 4;
const NOT_A_CODE = /[^A-Z0-9]/g;

const MESSAGES = {
    400: "That code doesn't match any identity. Check it and try again.",
    429: 'Too many attempts. Wait a minute and try again.',
};

const clean = (text) => text.toUpperCase().replace(NOT_A_CODE, '').slice(0, CODE_LENGTH);
const withDashes = (raw) => raw.replace(new RegExp(`(.{${GROUP_LENGTH}})(?=.)`, 'g'), '$1-');

/** Position in the formatted value that follows the first `count` characters of the code. */
const caretAfter = (count) => count + Math.max(0, Math.ceil(count / GROUP_LENGTH) - 1);

/** Writes the formatted code and leaves the caret after the same character it was following. */
function writeCode(input, text, caretText) {
    input.value = withDashes(clean(text));
    const caret = caretAfter(clean(caretText).length);
    input.setSelectionRange(caret, caret);
}

function setLoading(form, loading) {
    const submit = form.querySelector('#submit');
    submit.setAttribute('aria-busy', String(loading));
    form.setAttribute('aria-busy', String(loading));
    form.querySelector('#spinner').classList.toggle('hidden', !loading);
    form.querySelector('#submit-label').textContent = loading ? 'Signing in…' : 'Sign in';
}

export function initRecovery() {
    const root = document.querySelector('#recovery');
    const form = document.querySelector('#recovery-form');
    if (!root || !form) {
        return;
    }
    const input = form.elements.code;
    const error = form.querySelector('#code-error');
    const help = form.querySelector('#code-help');
    const submit = form.querySelector('#submit');
    let submitting = false;

    const isComplete = () => clean(input.value).length === CODE_LENGTH;
    const refresh = () => {
        input.toggleAttribute('data-complete', isComplete());
        submit.disabled = submitting || !isComplete();
    };
    const clearError = () => {
        error.classList.add('hidden');
        error.textContent = '';
        help.classList.remove('hidden');
        input.removeAttribute('aria-invalid');
        input.setAttribute('aria-describedby', 'code-help');
    };
    /** `invalid`: it is the code that was rejected, not the attempt (too many tries, no connection). */
    const showError = (message, invalid) => {
        error.textContent = message; // textContent only: never inject text as HTML
        error.classList.remove('hidden');
        help.classList.add('hidden');
        input.setAttribute('aria-describedby', 'code-error');
        if (invalid) {
            input.setAttribute('aria-invalid', 'true');
        }
    };

    input.addEventListener('input', (event) => {
        clearError();
        if (!event.isComposing) {
            writeCode(input, input.value, input.value.slice(0, input.selectionStart));
        }
        refresh();
    });
    input.addEventListener('compositionend', () => {
        writeCode(input, input.value, input.value.slice(0, input.selectionStart));
        refresh();
    });
    // Without this, maxlength would cut a code pasted with spaces or other separators before it can be cleaned.
    input.addEventListener('paste', (event) => {
        event.preventDefault();
        const pasted = event.clipboardData.getData('text');
        const before = input.value.slice(0, input.selectionStart) + pasted;
        clearError();
        writeCode(input, before + input.value.slice(input.selectionEnd), before);
        refresh();
    });
    window.addEventListener('pageshow', () => {
        input.value = withDashes(clean(input.value));
        refresh();
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (submitting || !isComplete()) {
            return;
        }

        submitting = true;
        clearError();
        setLoading(form, true);
        refresh();

        try {
            // The code only travels in this request body: it is never stored client-side.
            const response = await fetch(API.recover, {
                method: 'POST',
                headers: writeHeaders(),
                credentials: 'same-origin',
                cache: 'no-store',
                body: JSON.stringify({ code: input.value }),
            });

            if (response.ok) {
                input.value = '';
                window.location.assign(root.dataset.continueUrl);
                return;
            }

            showError(MESSAGES[response.status] ?? "We couldn't sign you in. Please try again.", response.status === 400);
            input.focus();
        } catch {
            showError("Can't reach the server. Check your connection and try again.", false);
        } finally {
            submitting = false;
            setLoading(form, false);
            refresh();
        }
    });
}
