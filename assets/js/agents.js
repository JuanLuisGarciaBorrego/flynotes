import { copyText } from './clipboard.js';

export function initAgents() {
    const button = document.querySelector('#copy-mcp-url');
    if (!button) {
        return;
    }
    const status = document.querySelector('#copy-mcp-status');

    button.addEventListener('click', async () => {
        try {
            await copyText(button.dataset.url, document.querySelector('#mcp-url'));
            status.textContent = 'URL copied';
        } catch {
            status.textContent = "Couldn't copy. Select the URL and copy it manually.";
        }
    });
}
