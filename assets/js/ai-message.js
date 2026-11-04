/** Wording of a plane's AI reinterpretation, shared by the launch page and the plane modal. */

/** What a visitor reads when the AI cannot answer: it is disabled, or the provider failed. Also used by Sky Pulse. */
export const AI_UNAVAILABLE = 'AI is temporarily unavailable. Paper planes are still flying!';

/** Label of the "another version" button: "Another version · 2 left", or "No more versions" at the limit. */
export function retryLabel(remaining) {
    return remaining > 0 ? `Another version · ${remaining} left` : 'No more versions';
}

/** Public note for a plane whose author turned the AI version down. Only for `rejected`, never `pending`. */
export function rejectedNote(emoji) {
    const inspiration = emoji ? `Inspired by the author's ${emoji} emoji` : "Inspired by the author's mood";

    return `${inspiration}, the AI wrote its own version of the message. But the human wasn't convinced. Today only the original flies!`;
}

/**
 * What a public reader sees about the AI version: the accepted text, the rejection note, or nothing (no proposal
 * yet, or still pending: the author has not decided, so nothing is claimed).
 *
 * @returns {{label: string, text: string}|null}
 */
export function publicAiView(plane) {
    if (plane.aiStatus === 'accepted' && plane.aiMessage) {
        return { label: `${plane.authorEmoji ?? '✨'} AI version, approved by the author`, text: plane.aiMessage };
    }
    if (plane.aiStatus === 'rejected') {
        return { label: 'AI version', text: rejectedNote(plane.authorEmoji) };
    }

    return null;
}
