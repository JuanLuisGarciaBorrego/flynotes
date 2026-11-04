// API Platform endpoints used by the frontend. Single place to change them.
export const API = Object.freeze({
    participants: '/api/participants',
    paperPlanes: '/api/paper_planes',
    myPaperPlanes: '/api/me/paper_planes',
    myPaperPlaneStats: '/api/me/paper_planes/stats',
    recover: '/api/participants/recover',
    agentMissions: '/api/agent_missions',
    currentAgentMission: '/api/agent_missions/current',
});

export const HOME_URL = '/';
// sessionStorage key carrying the just-sent message to the home page (not a secret).
export const SENT_KEY = 'flynotes:sent-message';

/** Single paper plane (message and author) in the API. */
export const planeApiUrl = (uuid) => `${API.paperPlanes}/${encodeURIComponent(uuid)}`;

/** AI reinterpretation of a plane, author only: ask for another proposal (POST, no body) or decide on it. */
export const aiRetryApiUrl = (uuid) => `${planeApiUrl(uuid)}/ai-message/retry`;
export const aiDecisionApiUrl = (uuid) => `${planeApiUrl(uuid)}/ai-message/decision`;

/** Stamps of a single paper plane (POST, no body: the server takes the country from the session). */
export const stampApiUrl = (uuid) => `${planeApiUrl(uuid)}/stamps`;

/** A plane opened over the home page (home.js reopens ?plane=<uuid>). */
export const planePageUrl = (uuid) => `${HOME_URL}?plane=${encodeURIComponent(uuid)}`;

/** A Sky Pulse mission (public) and the two human decisions on it. */
export const missionApiUrl = (uuid) => `${API.agentMissions}/${encodeURIComponent(uuid)}`;
export const missionToolDecisionApiUrl = (uuid) => `${missionApiUrl(uuid)}/tool-decision`;
export const missionDecisionApiUrl = (uuid) => `${missionApiUrl(uuid)}/decision`;

/** Common fetch options: JSON-LD in and out, identity cookie sent to the same origin. */
export const JSON_LD = Object.freeze({
    credentials: 'same-origin',
    headers: Object.freeze({ Accept: 'application/ld+json', 'Content-Type': 'application/ld+json' }),
});

/**
 * Headers for state-changing API calls: JSON-LD plus the `csrf-token` header that the server's
 * stateless CSRF protection requires (a fresh random value per request; cross-site pages cannot
 * add custom headers). The session itself travels in the HttpOnly cookie.
 */
export function writeHeaders() {
    const bytes = crypto.getRandomValues(new Uint8Array(24));
    const token = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');

    return { ...JSON_LD.headers, 'csrf-token': token };
}
