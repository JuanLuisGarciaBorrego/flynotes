import { API, HOME_URL, SENT_KEY, aiDecisionApiUrl, aiRetryApiUrl, planeApiUrl, writeHeaders, JSON_LD } from './api.js';
import { AI_UNAVAILABLE, retryLabel } from './ai-message.js';
import { flagEmoji } from './stamp.js';

/** Remaining characters at or below which the counter turns into a warning. */
const LOW_REMAINING = 20;
const RING_LENGTH = 81.68; // circumference of the counter's ring (r=13)
// The sheet's times match the transitions of .launch-sheet and .launch-plane in app.css.
const FOLD_MS = 450;
const FLIGHT_MS = 1000;
const CONFIRMATION_MS = 1100;
const IN_THE_AIR = 'Your plane is in the air';
const STILL_LAUNCHING = 'Launching your plane…';
const LAUNCH_LABEL = 'Fold & launch';

/**
 * Length as the server counts it: Symfony's Length constraint trims the message (normalizer: 'trim') and
 * counts code points, so an emoji is one character even though it is two UTF-16 units in `string.length`.
 */
export function messageLength(value) {
    return [...value.trim()].length;
}

/** "250 characters left", "1 character left", "0 characters left". */
export function remainingLabel(remaining) {
    return `${remaining} ${remaining === 1 ? 'character' : 'characters'} left`;
}

/** The client-side error for a message, or null when it can be sent; limits come from the field's attributes. */
export function lengthError(value, min, max) {
    const length = messageLength(value);
    if (length < min) {
        return `Your message needs at least ${min} characters.`;
    }
    if (length > max) {
        return `Your message can have at most ${max} characters.`;
    }

    return null;
}

/**
 * The text without what goes over the limit, counted like the server. Not a maxlength attribute: the browser counts
 * UTF-16 units (an emoji is two), so it would stop valid messages. Whitespace around it does not count, so it stays.
 */
export function withinLimit(value, max) {
    if (messageLength(value) <= max) {
        return value;
    }
    const leading = [...value.slice(0, value.length - value.trimStart().length)].length;

    return [...value].slice(0, leading + max).join('');
}

const wait = (ms) => new Promise((resolve) => window.setTimeout(resolve, ms));

function show(el, text) {
    el.textContent = text; // textContent only: never inject server data as HTML
    el.classList.remove('hidden');
}

export function initPaperPlane() {
    const root = document.querySelector('#paper-plane-new');
    const form = document.querySelector('#paper-plane-form');
    if (!root || !form) {
        return;
    }

    const fromFlag = form.querySelector('#from-flag');
    if (fromFlag) {
        fromFlag.textContent = flagEmoji(fromFlag.dataset.countryCode) ?? '';
    }

    const field = form.elements.message;
    const counter = form.querySelector('#message-counter');
    const count = form.querySelector('#message-count');
    const ring = form.querySelector('#message-ring');
    const sparks = form.querySelector('#message-sparks');
    const fieldError = form.querySelector('#message-error');
    const formError = form.querySelector('#form-error');
    const button = form.querySelector('#submit');
    const label = form.querySelector('#submit-label');
    const status = root.querySelector('#launch-status');
    // Single source of truth: minlength and data-max-length are rendered from App\Entity\PaperPlane by Twig.
    const min = field.minLength;
    const max = Number(field.dataset.maxLength);
    let submitting = false;

    const refresh = () => {
        const length = messageLength(field.value);
        const remaining = Math.max(0, max - length);
        count.textContent = remainingLabel(remaining);
        ring.setAttribute('stroke-dashoffset', String(RING_LENGTH * (remaining / max)));
        if (remaining === 0) {
            counter.dataset.level = 'out';
        } else if (remaining <= LOW_REMAINING) {
            counter.dataset.level = 'low';
        } else {
            delete counter.dataset.level;
        }
        sparks.hidden = length > 0;
        button.disabled = submitting || length === 0;
    };

    // `input` fires on typing, pasting, cutting and deleting alike. A composition in progress is left alone.
    const onInput = (event) => {
        if (!event.isComposing) {
            const allowed = withinLimit(field.value, max);
            if (allowed !== field.value) {
                field.value = allowed;
            }
        }
        refresh();
    };
    field.addEventListener('input', onInput);
    field.addEventListener('compositionend', onInput);
    refresh(); // the browser may restore a draft on back/forward navigation

    sparks.addEventListener('click', (event) => {
        const spark = event.target.closest('[data-spark]');
        if (spark) {
            field.value = spark.dataset.spark;
            field.focus();
            field.setSelectionRange(field.value.length, field.value.length);
            refresh();
        }
    });

    field.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
            event.preventDefault();
            if (!button.disabled) {
                form.requestSubmit();
            }
        }
    });

    const setSubmitting = (on) => {
        submitting = on;
        form.inert = on; // nothing to edit or focus inside a sheet that is flying away
        label.textContent = on ? 'Launching…' : LAUNCH_LABEL;
        refresh();
    };

    /** Sends the plane: its data, or null once the reason is on screen. */
    const send = async () => {
        try {
            const response = await fetch(API.paperPlanes, {
                method: 'POST',
                headers: writeHeaders(),
                credentials: 'same-origin',
                body: JSON.stringify({ message: field.value.trim() }),
            });
            const data = await response.json().catch(() => null);

            if (response.status === 201 && data) {
                return data;
            }
            if (response.status === 422 && Array.isArray(data?.violations)) {
                const general = [];
                for (const { propertyPath, message } of data.violations) {
                    if (propertyPath === 'message') {
                        show(fieldError, message);
                        field.setAttribute('aria-invalid', 'true');
                    } else {
                        general.push(message);
                    }
                }
                if (general.length > 0) {
                    show(formError, general.join(' '));
                }
            } else if (response.status === 401) {
                show(formError, 'Your identity has expired. Sign up again to send messages.');
            } else {
                show(formError, "We couldn't send your message. Please try again.");
            }
        } catch {
            show(formError, "Can't reach the server. Check your connection and try again.");
        }

        return null;
    };

    /** The sheet folds into a plane and the plane flies away; resolves when it is gone. Stops if the launch was undone. */
    const flyAway = async () => {
        root.dataset.launch = 'folding';
        await wait(FOLD_MS);
        if (root.dataset.launch === 'folding') {
            root.dataset.launch = 'flying';
            await wait(FLIGHT_MS);
        }
    };

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (submitting) {
            return;
        }
        formError.classList.add('hidden');
        fieldError.classList.add('hidden');
        field.removeAttribute('aria-invalid');

        // Same rule as the server (which still validates): nothing is sent outside the limits.
        const error = lengthError(field.value, min, max);
        if (error) {
            show(fieldError, error);
            field.setAttribute('aria-invalid', 'true');
            field.focus();
            return;
        }

        setSubmitting(true);
        const animated = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        let answered = false;
        // The answer can take long, because the AI writes its version first: the flight does not wait for it.
        const flight = animated
            ? flyAway().then(() => {
                if (!answered) {
                    status.textContent = STILL_LAUNCHING;
                }
            })
            : Promise.resolve();

        const plane = await send();
        answered = true;

        if (plane === null) {
            // Back to its place, with the text as it was.
            delete root.dataset.launch;
            status.textContent = '';
            setSubmitting(false);
            field.focus();
            return;
        }

        await flight;
        root.dataset.launch = 'done';
        // Not a secret: only used to show the success toast on the home page.
        sessionStorage.setItem(SENT_KEY, plane.message);
        status.textContent = IN_THE_AIR;
        await wait(CONFIRMATION_MS);
        // The plane is already published; the AI proposal, if any, only waits for the author's decision.
        status.textContent = '';
        root.classList.add('invisible'); // the dialog sits over the sky, not over the form
        initAiReview(plane);
    });
}

/** Readable error for a failed AI call (API Platform puts the reason in `detail`/`description`). */
function aiErrorMessage(status, data, fallback) {
    if (status === 401) {
        return 'Your identity has expired. Sign in again to decide on the AI version.';
    }
    if (status === 403) {
        return "You can't change the AI version of this plane.";
    }
    if (status === 404) {
        return 'This plane no longer exists.';
    }
    if (status === 422 && Array.isArray(data?.violations)) {
        return data.violations.map(({ message }) => message).join(' ');
    }
    if (status === 409) {
        return data?.detail ?? data?.description ?? 'This AI version can no longer be changed.';
    }
    if (status === 503) {
        return AI_UNAVAILABLE;
    }

    return fallback;
}

/** Browser-side limit of one generation; the server's generator must give up before it (see PaperPlaneAiManager). */
const GENERATION_TIMEOUT_MS = 45_000;
const GENERATION_STEP_MS = 1_500;
const GENERATION_STEPS = ['Reading your message…', 'Choosing a tone…', 'Writing a new version…'];
const DECISION_FAILED = "We couldn't save your decision. Please try again.";

/**
 * Human in the loop after launching, in a modal dialog: the original, the AI version and its tone. "Another version"
 * replaces the one on screen, and only that one can be approved: "Let it fly too" sends its id and the server refuses
 * it if a newer one exists. "No, only mine" discards them all. Without a proposal (the generation failed) only
 * asking the AI again is possible: there is nothing to decide on yet. Escape or closing without deciding publishes
 * nothing (the plane already flies with the original). While another version is being written the dialog is fully
 * blocked: nothing can be clicked and it cannot be closed, until the answer arrives, fails or times out.
 */
function initAiReview(initial) {
    const dialog = document.querySelector('#ai-dialog');
    if (!dialog) {
        window.location.assign(HOME_URL);
        return;
    }
    const $ = (selector) => dialog.querySelector(selector);
    const buttons = [...dialog.querySelectorAll('[data-ai-action]')];
    const button = (action) => dialog.querySelector(`[data-ai-action="${action}"]`);
    const closeButton = $('[data-ai-close]');
    const leaveButton = $('[data-ai-leave]');
    const reviewTitle = $('#ai-title').textContent;
    const setLabel = (action, text) => {
        (button(action).querySelector('[data-ai-label]') ?? button(action)).textContent = text;
    };
    const error = $('#ai-error');
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    let plane = initial;
    let busy = null; // decision in flight ('accept' | 'reject'), if any
    let isGenerating = false; // another version is being written: everything is blocked
    let blocked = false; // the server answered 403/404: no AI action can succeed from here
    let stepTimer = null;

    const isDecided = () => plane.aiStatus === 'accepted' || plane.aiStatus === 'rejected';

    const showView = (view) => {
        for (const panel of dialog.querySelectorAll('[data-ai-view]')) {
            panel.classList.toggle('hidden', panel.dataset.aiView !== view);
        }
    };

    const paintDecided = () => {
        for (const item of [...buttons, leaveButton]) {
            item.disabled = true;
        }
        closeButton.disabled = false;
        for (const original of dialog.querySelectorAll('[data-ai-final-original]')) {
            original.textContent = plane.message ?? '';
        }
        // Only the server's answer counts here: `aiMessage` is the approved text, and null once rejected.
        $('#ai-final-message').textContent = plane.aiMessage ?? '';
        const tone = $('#ai-final-tone');
        tone.textContent = plane.aiTone ?? '';
        tone.classList.toggle('hidden', !plane.aiTone);
        showView(plane.aiStatus);
        dialog.querySelector(`[data-ai-view="${plane.aiStatus}"] [data-ai-final-title]`).focus();
    };

    const paint = () => {
        if (isDecided()) {
            paintDecided();
            return;
        }
        showView('review');
        dialog.setAttribute('aria-busy', String(isGenerating));
        const hasProposal = typeof plane.aiMessage === 'string' && Boolean(plane.aiVersionId);
        // Server data goes in with textContent only.
        $('#ai-title').textContent = hasProposal || isGenerating ? reviewTitle : AI_UNAVAILABLE;
        $('#ai-original').textContent = plane.message ?? '';
        $('#ai-proposal').textContent = plane.aiMessage ?? '';
        $('#ai-proposal').classList.toggle('hidden', isGenerating || !hasProposal);
        $('#ai-none').classList.toggle('hidden', isGenerating || hasProposal);
        $('#ai-generating').classList.toggle('hidden', !isGenerating);
        $('#ai-version').dataset.generating = String(isGenerating);
        const tone = $('#ai-tone');
        tone.textContent = plane.aiTone ?? '';
        tone.classList.toggle('hidden', isGenerating || !plane.aiTone); // back with the new version
        $('#ai-hold').classList.toggle('hidden', !isGenerating);
        $('#ai-hold').classList.toggle('flex', isGenerating);

        const locked = isGenerating || busy !== null || blocked;
        const remaining = Number.isInteger(plane.aiAttemptsRemaining) ? plane.aiAttemptsRemaining : 0;

        // Real `disabled` attributes, not only styles.
        for (const item of buttons) {
            item.disabled = locked;
        }
        button('retry').disabled ||= remaining <= 0; // only this one: approving or rejecting stays possible
        button('accept').disabled ||= !hasProposal;
        // Nothing to decide on without a proposal: the server would refuse both.
        button('accept').classList.toggle('hidden', !hasProposal);
        button('accept').classList.toggle('inline-flex', hasProposal);
        button('reject').classList.toggle('hidden', !hasProposal);
        leaveButton.classList.toggle('hidden', hasProposal);
        leaveButton.disabled = isGenerating;
        closeButton.disabled = isGenerating;
        if (isGenerating) {
            closeButton.title = 'Wait until the AI finishes';
        } else {
            closeButton.removeAttribute('title');
        }
        setLabel('retry', hasProposal || remaining <= 0 ? retryLabel(remaining) : `Ask the AI again · ${remaining} left`);
        setLabel('accept', busy === 'accept' ? 'Sending…' : 'Let it fly too');
        button('reject').textContent = busy === 'reject' ? 'Discarding…' : 'No, only mine';
    };

    /** Back to a sensible control once a request ends: the first one that can still be used. */
    const focusNextAction = () => {
        if (!isDecided()) {
            [button('retry'), button('accept'), leaveButton, closeButton].find((item) => !item.disabled && !item.classList.contains('hidden'))?.focus();
        }
    };

    const warnBeforeLeaving = (event) => {
        event.preventDefault();
        event.returnValue = ''; // older browsers need it to show the prompt
    };

    const setGenerating = (on) => {
        isGenerating = on;
        window.clearInterval(stepTimer);
        if (on) {
            window.addEventListener('beforeunload', warnBeforeLeaving);
            const step = $('#ai-generating-step');
            if (reducedMotion.matches) {
                step.textContent = GENERATION_STEPS.at(-1); // fixed text, no rotation
            } else {
                let index = 0;
                step.textContent = GENERATION_STEPS[index];
                // Advances once per step and stays on the last one: it never seems to start over.
                stepTimer = window.setInterval(() => {
                    index = Math.min(index + 1, GENERATION_STEPS.length - 1);
                    step.textContent = GENERATION_STEPS[index];
                }, GENERATION_STEP_MS);
            }
        } else {
            window.removeEventListener('beforeunload', warnBeforeLeaving);
        }
        paint();
    };

    const fetchPlane = () => fetch(planeApiUrl(plane.uuid), { ...JSON_LD, cache: 'no-store' })
        .then((r) => (r.ok ? r.json() : null))
        .catch(() => null);

    // After a failed call the server has the last word: the latest version (the generation may have finished after
    // the browser gave up), the counters and a decision taken elsewhere. Returns whether the version changed.
    const resync = async () => {
        const fresh = await fetchPlane();
        if (!fresh) {
            return false;
        }
        const isNew = fresh.aiVersionId !== plane.aiVersionId;
        plane = fresh;

        return isNew;
    };

    const stopIfGone = (status) => {
        blocked ||= status === 403 || status === 404;
    };

    const retry = async () => {
        // The flag goes up before anything else: a second click finds it set.
        if (isGenerating || busy !== null || blocked || isDecided()) {
            return;
        }
        error.classList.add('hidden');
        setGenerating(true);
        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), GENERATION_TIMEOUT_MS);

        try {
            const response = await fetch(aiRetryApiUrl(plane.uuid), {
                method: 'POST', headers: writeHeaders(), credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
            });
            const data = await response.json().catch(() => null);
            if (response.ok && data) {
                plane = data; // new version, tone and "{n} left"
                return;
            }
            stopIfGone(response.status);
            show(error, aiErrorMessage(response.status, data, AI_UNAVAILABLE));
            if (!blocked) {
                await resync();
            }
        } catch {
            // Network error or the 45 s timeout. If the version did arrive in the meantime, nothing failed for the author.
            if (!await resync()) {
                show(error, AI_UNAVAILABLE);
            }
        } finally {
            window.clearTimeout(timeout);
            setGenerating(false);
            focusNextAction();
        }
    };

    const decide = async (action) => {
        if (isGenerating || busy !== null || blocked || isDecided()) {
            return;
        }
        error.classList.add('hidden');
        busy = action;
        paint();
        // Accept sends the id of the version on screen: a newer one on the server makes it fail, it never approves another.
        const body = JSON.stringify({ accepted: action === 'accept', versionId: action === 'accept' ? plane.aiVersionId : null });

        try {
            const response = await fetch(aiDecisionApiUrl(plane.uuid), {
                method: 'POST', headers: writeHeaders(), credentials: 'same-origin', cache: 'no-store', body,
            });
            const data = await response.json().catch(() => null);
            if (response.ok && data) {
                plane = data;
            } else {
                stopIfGone(response.status);
                show(error, aiErrorMessage(response.status, data, DECISION_FAILED));
                if (response.status === 409) {
                    await resync(); // decided elsewhere: show it as the server has it
                }
            }
        } catch {
            show(error, "Can't reach the server. Check your connection and try again.");
        } finally {
            busy = null;
            paint();
            focusNextAction();
        }
    };

    button('retry').addEventListener('click', retry);
    button('accept').addEventListener('click', () => decide('accept'));
    button('reject').addEventListener('click', () => decide('reject'));
    for (const closer of [closeButton, leaveButton]) {
        closer.addEventListener('click', () => {
            if (!isGenerating) {
                dialog.close();
            }
        });
    }

    // No way out while generating. `cancel` covers Escape; the keydown guard also stops Chrome from forcing the
    // close on a repeated Escape; and if the dialog still gets closed, it is reopened at once.
    dialog.addEventListener('cancel', (event) => {
        if (isGenerating) {
            event.preventDefault();
        }
    });
    dialog.addEventListener('keydown', (event) => {
        if (isGenerating && event.key === 'Escape') {
            event.preventDefault();
        }
    });
    // The dialog has no light dismiss (a click on the backdrop never closes it), so there is nothing to block there.
    dialog.addEventListener('close', () => {
        if (isGenerating) {
            dialog.showModal();
            return;
        }
        // Escape, the close button or "Back to the sky": nothing more is published; the plane already flies.
        window.location.assign(HOME_URL);
    });

    paint();
    dialog.showModal();
    $('#ai-title').focus();
}
