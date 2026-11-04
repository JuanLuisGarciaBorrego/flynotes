/**
 * Copies `text` to the clipboard. Without the async Clipboard API (e.g. plain HTTP on a LAN host),
 * selects the visible `node` and falls back to the legacy copy command. Rejects if both fail.
 */
export async function copyText(text, node) {
    if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(text);

        return;
    }

    const range = document.createRange();
    range.selectNodeContents(node);
    const selection = window.getSelection();
    selection.removeAllRanges();
    selection.addRange(range);
    if (!document.execCommand('copy')) {
        throw new Error('copy failed');
    }
}
