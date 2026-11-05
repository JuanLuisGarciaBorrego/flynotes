import { copyText } from './clipboard.js';

export function initAgents() {
    document.querySelectorAll('[data-copy-target]').forEach(initCopyButton);
    initTabs(document.querySelector('#client-tabs'));
}

function initCopyButton(button) {
    const node = document.getElementById(button.dataset.copyTarget);
    const status = document.getElementById(`${button.dataset.copyTarget}-status`);

    button.addEventListener('click', async () => {
        try {
            await copyText(node.textContent.trim(), node);
            status.textContent = button.dataset.copied;
        } catch {
            status.textContent = "Couldn't copy. Select the text and copy it manually.";
        }
    });
}

function initTabs(tablist) {
    if (!tablist) {
        return;
    }
    const tabs = [...tablist.querySelectorAll('[role="tab"]')];
    const panelOf = (tab) => document.getElementById(tab.getAttribute('aria-controls'));

    const select = (selected) => {
        tabs.forEach((tab) => {
            const active = tab === selected;
            tab.setAttribute('aria-selected', String(active));
            tab.tabIndex = active ? 0 : -1;
            panelOf(tab).classList.toggle('hidden', !active);
        });
    };

    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => select(tab));
        tab.addEventListener('keydown', (event) => {
            const next = {
                ArrowRight: tabs[(index + 1) % tabs.length],
                ArrowLeft: tabs[(index - 1 + tabs.length) % tabs.length],
                Home: tabs[0],
                End: tabs[tabs.length - 1],
            }[event.key];
            if (next) {
                event.preventDefault();
                select(next);
                next.focus();
            }
        });
    });

    // A link to something inside a closed tab opens that tab first.
    const openLinkedTab = () => {
        const target = location.hash && document.getElementById(location.hash.slice(1));
        const tab = target && tabs.find((candidate) => panelOf(candidate).contains(target));
        if (tab) {
            select(tab);
            target.scrollIntoView();
        }
    };
    window.addEventListener('hashchange', openLinkedTab);
    openLinkedTab();
}
