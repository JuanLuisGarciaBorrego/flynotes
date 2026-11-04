import {
    API,
    JSON_LD,
    missionApiUrl,
    missionDecisionApiUrl,
    missionToolDecisionApiUrl,
    planePageUrl,
    writeHeaders,
} from './api.js';
import { AI_UNAVAILABLE } from './ai-message.js';
import { UUID_PATTERN } from './plane-modal.js';
import { countryName, flagEmoji } from './stamp.js';

/**
 * The Sky Pulse page. The mission is always what the server last answered: nothing about it is kept or decided here,
 * this only turns it into text and hidden sections. Server data goes in with textContent only.
 *
 * Other humans can decide on the same mission, and a mission can be running in another request, so an active mission
 * is read again every few seconds.
 */
const POLL_MS = 4000;
const PUBLISH_TOOL = 'publish_sky_report';
const ACTIVE_STATUSES = ['running', 'waiting_tool_approval', 'waiting_approval'];
const LATEST_EVENTS = 5;
const SHORT_ID_LENGTH = 8;
const EVIDENCE_GROUPS = 3;

/** What each tool does, for any visitor. A tool not listed here shows its internal name. */
const TOOLS = {
    get_sky_emojis: {
        label: 'Emoji census',
        description: 'Count which profile emojis the community uses. Group totals only: nobody appears individually.',
    },
    get_sky_countries: {
        label: 'Country map',
        description: 'Count participants by country. Totals only: nobody appears individually.',
    },
    get_sky_activity: {
        label: 'Activity pulse',
        description: 'Count the paper planes, reads and stamps across all of FlyNotes.',
    },
    [PUBLISH_TOOL]: { label: 'Paper plane publication', description: '' },
};
const toolLabel = (name) => TOOLS[name]?.label ?? name;

const ACTIVITY_LABELS = { planes: ['plane', 'planes'], views: ['read', 'reads'], stamps: ['stamp', 'stamps'] };

// en-GB: 24-hour clock and "4 Nov".
const CLOCK = new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
const DAY = new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short' });
const HOUR = new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit' });
const NUMBER = new Intl.NumberFormat('en');

const hasStep = (mission, type, toolName) => mission.steps.some((step) => step.type === type && (toolName === undefined || step.toolName === toolName));

/** The research tool of the mission: the first one a human was asked about. */
const researchTool = (mission) => mission.steps.find((step) => step.type === 'approval_requested' && step.toolName !== PUBLISH_TOOL)?.toolName ?? null;

/** Position in the flight plan of the template, from the checkpoints that really happened. */
function flightPhase(mission) {
    if (hasStep(mission, 'completed')) {
        return 6;
    }
    const reached = [['proposal_created', 4], ['tool_succeeded', 3], ['approved', 2], ['approval_requested', 1]];

    return reached.find(([type]) => hasStep(mission, type))?.[1] ?? 0;
}

/** What the agent is doing while it runs. @returns {{title: string, text: string}} */
function runningView(mission) {
    return [
        { title: 'Sky Pulse is choosing what to research', text: 'Nothing has run yet.' },
        { title: 'Sky Pulse is choosing what to research', text: 'Nothing has run yet.' },
        { title: 'Sky Pulse is researching', text: 'A human approved the tool and it is running.' },
        { title: 'Sky Pulse is writing its proposal', text: 'The research is done. A human decides next.' },
    ][flightPhase(mission)] ?? { title: 'Sky Pulse is working', text: '' };
}

/** The status pill of the header. */
function statusLabel(status, canDecide) {
    return {
        running: 'Sky Pulse is working',
        waiting_tool_approval: canDecide ? 'Awaiting your approval' : 'Awaiting human approval',
        waiting_approval: canDecide ? 'Awaiting your approval' : 'Awaiting human approval',
        completed: 'Published · in the sky',
        rejected: 'Rejected by a human',
        failed: 'Mission failed',
    }[status] ?? String(status);
}

const statusTone = (status) => (status.startsWith('waiting') ? 'waiting' : status);

const clock = (iso) => {
    const date = new Date(iso);

    return Number.isNaN(date.getTime()) ? '' : CLOCK.format(date);
};

/** When a human approved that tool, from the checkpoints. */
const approvedAt = (mission, tool) => {
    const step = mission.steps.find(({ type, toolName }) => type === 'approved' && toolName === tool);

    return step ? clock(step.createdAt) : null;
};

/** The foot of the card once there is nothing to decide. @returns {{title: string, text: string}} */
function outcomeView(mission) {
    switch (mission.status) {
        case 'running':
            return runningView(mission);
        case 'completed': {
            const at = approvedAt(mission, PUBLISH_TOOL) ?? clock(mission.steps.at(-1)?.createdAt);

            return { title: 'Published. The plane is in the sky.', text: `A human approved it at ${at}.` };
        }
        case 'rejected':
            return {
                title: 'Rejected. Nothing was published.',
                text: hasStep(mission, 'proposal_created') ? 'Sky Pulse keeps the draft for the record.' : 'The research tool never ran.',
            };
        case 'failed':
            return { title: AI_UNAVAILABLE, text: "Sky Pulse couldn't finish, and no paper plane was created." };
        default:
            return { title: '', text: '' };
    }
}

/** Where the research tool stands: never "executed" before the server says so. */
function toolState(mission, tool) {
    if (hasStep(mission, 'tool_succeeded', tool)) {
        return 'Completed';
    }
    if (hasStep(mission, 'tool_failed', tool)) {
        return 'Failed to run';
    }
    if (!hasStep(mission, 'approved', tool)) {
        return mission.status === 'waiting_tool_approval' ? 'Awaiting approval' : 'Not run';
    }

    return 'Running';
}

function proposalState(status) {
    return { completed: 'Published', rejected: 'Rejected · not published', failed: 'Not published' }[status] ?? 'Draft · not published';
}

/** @returns {{actor: string, text: string}} */
function stepView(step, mission) {
    const publishing = step.toolName === PUBLISH_TOOL;
    const tool = toolLabel(step.toolName);

    switch (step.type) {
        case 'started':
            return { actor: 'ai', text: 'Sky Pulse starts exploring' };
        case 'tool_called':
            return { actor: 'ai', text: publishing ? 'Asks to publish its report' : `Chooses: ${tool}` };
        case 'tool_succeeded':
            return { actor: 'ai', text: 'Research completed' };
        case 'tool_failed':
            return { actor: 'ai', text: `The tool failed: ${tool}` };
        case 'proposal_created':
            return { actor: 'ai', text: 'Writes its proposal' };
        case 'approval_requested':
            return { actor: 'ai', text: publishing ? 'Asks a human to publish the plane' : 'Asks a human to approve the task' };
        case 'approved':
            return { actor: 'human', text: publishing ? 'A human publishes the plane' : `A human approves: ${tool}` };
        case 'rejected':
            // The last step of a rejected mission is the human's. Any other is an extra tool: only one runs per mission.
            return step.sequence === mission.steps.at(-1).sequence && mission.status === 'rejected'
                ? { actor: 'human', text: publishing ? 'A human rejects the proposal' : `A human rejects: ${tool}` }
                : { actor: 'ai', text: `Discarded: ${tool} (one tool per mission)` };
        case 'resumed':
            return { actor: 'ai', text: 'The mission continues' };
        case 'completed':
            return { actor: 'plane', text: 'Plane takes off' };
        case 'failed':
            return { actor: 'failed', text: 'The mission fails' };
        default:
            return { actor: 'ai', text: String(step.type) };
    }
}

function el(tag, className, text) {
    const node = document.createElement(tag);
    node.className = className;
    if (text !== undefined) {
        node.textContent = text;
    }

    return node;
}

/** "Mission 01a1a80e · started 4 Nov, 20:34". */
function missionMeta(mission) {
    const date = new Date(mission.createdAt);
    const id = `Mission ${mission.uuid.slice(0, SHORT_ID_LENGTH)}`;

    return Number.isNaN(date.getTime()) ? id : `${id} · started ${DAY.format(date)}, ${HOUR.format(date)}`;
}

/** Label of a row of a country or emoji result. Missions run before every group was listed may still hold "other". */
function groupLabel(row) {
    const value = row.countryCode ?? row.emoji;
    if (value === 'other') {
        return 'Others';
    }
    const flag = row.countryCode === undefined ? null : flagEmoji(value);

    return flag ? `${flag} ${countryName(value)}` : String(value);
}

const isGroupList = (result) => Array.isArray(result) && result.every((row) => Number.isInteger(row?.total) && ('countryCode' in row || 'emoji' in row));
const isActivity = (result) => result !== null && typeof result === 'object' && Object.keys(ACTIVITY_LABELS).every((key) => Number.isInteger(result[key]));

function chip(strong, text = '') {
    const node = el('span', 'rounded-full bg-white px-2.5 py-0.5 text-[13px] text-ink');
    node.append(el('strong', 'font-semibold', strong));
    if (text !== '') {
        node.append(` ${text}`);
    }

    return node;
}

/** A tool's real result in one line: "6 planes · 5 reads · 1 stamp". The full result is in the details. */
function evidenceChips(result) {
    if (isActivity(result)) {
        return Object.entries(ACTIVITY_LABELS).map(([key, [one, many]]) => chip(NUMBER.format(result[key]), result[key] === 1 ? one : many));
    }
    if (isGroupList(result)) {
        if (result.length === 0) {
            return [el('span', 'rounded-full bg-white px-2.5 py-0.5 text-[13px] text-ink', 'Nothing to count yet')];
        }
        const chips = result.slice(0, EVIDENCE_GROUPS).map((row) => chip(NUMBER.format(row.total), groupLabel(row)));
        if (result.length > EVIDENCE_GROUPS) {
            chips.push(el('span', 'rounded-full bg-white px-2.5 py-0.5 text-[13px] text-ink', `+${result.length - EVIDENCE_GROUPS} more`));
        }

        return chips;
    }

    return [];
}

const lowerFirst = (text) => text.charAt(0).toLowerCase() + text.slice(1);

export function initSkyPulse() {
    const root = document.querySelector('#sky-pulse');
    if (!root) {
        return;
    }
    const $ = (selector) => root.querySelector(selector);

    let mission = null; // what the server last answered; null on the initial screen
    let painted = null; // that same answer as text, to repaint only when it changes
    let busy = false;
    let showAllEvents = false;
    let pollTimer = null;
    let requestCount = 0; // an answer to an older request than the last action is out of date

    function showNotice(text, canRetry = false) {
        $('#sp-notice-text').textContent = text;
        $('#sp-notice').hidden = text === '';
        $('#sp-retry').hidden = !canRetry;
    }

    function showWorking(text) {
        busy = text !== null;
        ++requestCount;
        $('#sp-working').hidden = !busy;
        $('#sp-working-text').textContent = text ?? '';
        root.setAttribute('aria-busy', String(busy));
        root.querySelectorAll('button').forEach((button) => {
            button.disabled = busy;
        });
    }

    /** Reads or writes the API. Never throws: a network failure is status 0. */
    async function callApi(url, body) {
        const options = body === undefined ? { ...JSON_LD } : { ...JSON_LD, method: 'POST', headers: writeHeaders(), body: JSON.stringify(body) };
        try {
            const response = await fetch(url, options);

            return { status: response.status, data: response.ok ? await response.json() : null };
        } catch {
            return { status: 0, data: null };
        }
    }

    function renderIntro(lastMission) {
        window.clearTimeout(pollTimer);
        mission = null;
        painted = null;
        $('#sp-mission').hidden = true;
        $('#sp-summary').hidden = true;
        $('#sp-flight').hidden = true;
        $('#sp-intro').hidden = false;
        $('#sp-last').hidden = lastMission === null;
        if (lastMission !== null) {
            $('#sp-last').textContent = `See the last mission: ${statusLabel(lastMission.status, false)}`;
            $('#sp-last').href = `${window.location.pathname}?mission=${encodeURIComponent(lastMission.uuid)}`;
        }
    }

    /** The proposal once there is one; until then, the research that leads to it. */
    function renderCard() {
        const tool = researchTool(mission) ?? mission.pendingTool?.name ?? null;
        const message = mission.proposedMessage ?? null;
        const running = runningView(mission);

        $('#sp-card-label').textContent = message === null ? 'Research request' : 'Proposed paper plane';
        $('#sp-proposal-title').textContent = message === null ? (tool === null ? running.title : toolLabel(tool)) : mission.title ?? '';
        $('#sp-proposal-message').textContent = message ?? (tool === null ? running.text : TOOLS[tool]?.description ?? '');
        $('#sp-proposal-state').textContent = message === null ? (tool === null ? '' : toolState(mission, tool)) : proposalState(mission.status);

        const step = mission.steps.find(({ type, toolName }) => type === 'tool_succeeded' && toolName === tool);
        const hasResult = step !== undefined && 'result' in step;
        $('#sp-tool-code').hidden = tool === null || hasResult || message !== null;
        $('#sp-tool-code').textContent = tool ?? '';
        $('#sp-evidence').hidden = !hasResult;
        if (hasResult) {
            const description = TOOLS[tool]?.description ?? '';
            const at = approvedAt(mission, tool);
            $('#sp-evidence-tool').textContent = tool;
            $('#sp-evidence-chips').replaceChildren(...evidenceChips(step.result));
            $('#sp-evidence-task').textContent = description === '' ? toolLabel(tool) : `${toolLabel(tool)} — ${lowerFirst(description)}`;
            $('#sp-evidence-result').textContent = JSON.stringify(step.result, null, 2);
            $('#sp-evidence-note').textContent = at === null ? 'Real result from the tool' : `Real result from the tool · approved by a human at ${at}`;
        }
    }

    function renderDecision() {
        const pending = ['waiting_tool_approval', 'waiting_approval'].includes(mission.status);
        $('#sp-tool-decision').hidden = mission.status !== 'waiting_tool_approval';
        $('#sp-publish-decision').hidden = mission.status !== 'waiting_approval';
        $('#sp-outcome').hidden = pending;
        if (!pending) {
            const { title, text } = outcomeView(mission);
            $('#sp-outcome').dataset.tone = statusTone(mission.status);
            $('#sp-outcome-title').textContent = title;
            $('#sp-outcome-text').textContent = text;
        }
        $('#sp-published-link').hidden = !mission.publishedPlane;
        if (mission.publishedPlane) {
            $('#sp-published-link').href = planePageUrl(mission.publishedPlane);
        }
    }

    function renderFlight() {
        const phase = flightPhase(mission);
        const stopped = ['rejected', 'failed'].includes(mission.status);
        $('#sp-flight').querySelectorAll('li').forEach((item, index) => {
            item.removeAttribute('aria-current');
            if (index < phase) {
                item.dataset.state = 'done';
            } else if (index > phase) {
                item.dataset.state = 'todo';
            } else {
                item.dataset.state = stopped ? 'stopped' : 'current';
                item.setAttribute('aria-current', 'step');
            }
        });
    }

    /** Newest first, so that a decision appears at the top. */
    function renderTimeline() {
        const lastActive = ACTIVE_STATUSES.includes(mission.status) ? mission.steps.at(-1)?.sequence : null;
        const events = [...mission.steps].reverse();
        const earlier = events.length - LATEST_EVENTS;
        const visible = showAllEvents ? events : events.slice(0, LATEST_EVENTS);

        $('#sp-timeline').replaceChildren(...visible.map((step, index) => {
            const { actor, text } = stepView(step, mission);
            const item = el('li', 'grid grid-cols-[14px_minmax(0,1fr)_auto] items-start gap-2.5 border-b border-[#F1F5F9] py-[7px]');
            const dot = el('span', `pulse-dot mt-[5px]${step.sequence === lastActive ? ' pulse-dot-live' : ''}`);
            dot.dataset.actor = actor;
            dot.setAttribute('aria-hidden', 'true');
            const time = el('time', 'font-label text-xs font-medium leading-[1.6] text-ink-muted', clock(step.createdAt));
            time.dateTime = step.createdAt;
            item.append(dot, el('span', `text-sm leading-[1.35]${index === 0 ? ' font-semibold' : ''}`, text), time);

            return item;
        }));

        const toggle = $('#sp-timeline-toggle');
        toggle.hidden = earlier <= 0;
        toggle.textContent = showAllEvents ? 'Show only the latest' : `Show ${earlier} earlier ${earlier === 1 ? 'event' : 'events'}`;
    }

    function renderMission(data) {
        mission = data;
        const answer = JSON.stringify(data);
        if (answer !== painted) {
            painted = answer;
            const steps = mission.steps.length;
            $('#sp-intro').hidden = true;
            $('#sp-mission').hidden = false;
            $('#sp-summary').hidden = false;
            $('#sp-flight').hidden = false;
            $('#sp-status').dataset.tone = statusTone(mission.status);
            $('#sp-status-label').textContent = statusLabel(mission.status, root.querySelector('[data-decision]') !== null);
            $('#sp-metrics').textContent = `${NUMBER.format(steps)} ${steps === 1 ? 'checkpoint' : 'checkpoints'} · ${NUMBER.format(mission.totalTokens)} tokens`;
            $('#sp-mission-meta').textContent = missionMeta(mission);
            renderFlight();
            renderCard();
            renderDecision();
            renderTimeline();
            $('#sp-back').hidden = ACTIVE_STATUSES.includes(mission.status);
        }

        // A reload brings this very mission back.
        window.history.replaceState(null, '', `${window.location.pathname}?mission=${encodeURIComponent(mission.uuid)}`);

        window.clearTimeout(pollTimer);
        if (ACTIVE_STATUSES.includes(mission.status)) {
            pollTimer = window.setTimeout(pollMission, POLL_MS);
        }
    }

    async function pollMission() {
        const startedAt = requestCount;
        const { data } = await callApi(missionApiUrl(mission.uuid));
        if (busy || startedAt !== requestCount) {
            return; // an action started meanwhile, and its answer is the newer one
        }
        if (data) {
            renderMission(data);
        } else {
            pollTimer = window.setTimeout(pollMission, POLL_MS);
        }
    }

    /** The mission of the URL. @returns whether it could be read */
    async function loadMission(uuid) {
        const { status, data } = await callApi(missionApiUrl(uuid));
        if (data) {
            renderMission(data);

            return true;
        }
        if (status === 404) {
            showNotice("We couldn't find that mission.");
            window.history.replaceState(null, '', window.location.pathname);
            await loadCurrent();
        } else {
            showNotice("We couldn't read the mission. Please try again.", true);
        }

        return false;
    }

    /** No mission in the URL: the active one takes over the page, otherwise the initial screen. */
    async function loadCurrent() {
        const { status, data } = await callApi(API.currentAgentMission);
        if (data && ACTIVE_STATUSES.includes(data.status)) {
            renderMission(data);
        } else if (data || status === 404) {
            renderIntro(data);
        } else {
            showNotice("We couldn't load Sky Pulse. Please try again.", true);
        }
    }

    /** What is shown again, from the server: the mission on screen, or the current one. */
    const reload = () => (mission ? loadMission(mission.uuid) : loadCurrent());

    /** Sends one action: buttons off and a loader while it lasts, and what the server answers is what is painted. */
    async function send(workingText, url, body) {
        if (busy) {
            return;
        }
        window.clearTimeout(pollTimer);
        showNotice('');
        showWorking(workingText);
        // The buttons are further down the page than the loader: on a phone it would be off screen.
        $('#sp-working').scrollIntoView({ block: 'nearest' });
        const { status, data } = await callApi(url, body);

        if (data) {
            showWorking(null);
            renderMission(data);

            return;
        }

        if (status === 422) {
            showNotice('The mission changed while you were deciding: this is the latest we know about it.');
        } else if (status === 401 || status === 403) {
            showNotice('Only registered human participants can do this. Sign up or use your code.');
        } else if (status === 503) {
            showNotice(AI_UNAVAILABLE);
        } else {
            showNotice('Something went wrong. Check the mission status before trying again.');
        }
        await reload();
        showWorking(null);
    }

    const startMission = () => send('Sky Pulse is choosing a tool', API.agentMissions, {});

    const decideTool = (accepted) => send(
        accepted ? 'Sky Pulse is researching and writing its proposal' : 'Saving your decision',
        missionToolDecisionApiUrl(mission.uuid),
        { accepted },
    );

    const decidePublication = (accepted) => send(
        accepted ? 'Publishing the plane' : 'Saving your decision',
        missionDecisionApiUrl(mission.uuid),
        { accepted },
    );

    $('#sp-start')?.addEventListener('click', startMission);
    $('#sp-timeline-toggle').addEventListener('click', () => {
        showAllEvents = !showAllEvents;
        renderTimeline();
    });
    $('#sp-retry').addEventListener('click', async () => {
        showNotice('');
        showWorking('Trying again');
        await open();
        showWorking(null);
    });
    root.querySelectorAll('[data-decision]').forEach((button) => {
        const accepted = button.dataset.accepted === 'true';
        button.addEventListener('click', () => (button.dataset.decision === 'tool' ? decideTool(accepted) : decidePublication(accepted)));
    });

    /** The first paint: a well-formed UUID in the URL, otherwise the current mission. */
    function open() {
        if (mission) {
            return reload();
        }
        const uuid = new URLSearchParams(window.location.search).get('mission');

        return uuid !== null && UUID_PATTERN.test(uuid) ? loadMission(uuid) : loadCurrent();
    }

    showWorking('Looking for the current mission');
    open().finally(() => showWorking(null));
}
