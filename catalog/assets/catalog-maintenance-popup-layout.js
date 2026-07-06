(function () {
    'use strict';

    var messageSelectors = [
        '.full-sync-message',
        '.catalog-maintenance-message'
    ].join(',');

    function addStyle() {
        var style = document.createElement('style');
        style.textContent = [
            '.full-sync-message, .catalog-maintenance-message { min-height: 3.25em; margin-bottom: 16px; }',
            '.catalog-maintenance-message__label, .catalog-maintenance-message__detail { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }',
            '.catalog-maintenance-message__label { min-height: 1.45em; }',
            '.catalog-maintenance-message__detail { min-height: 1.45em; color: var(--muted); font-size: .92em; }'
        ].join('\n');
        document.head.appendChild(style);
    }

    function isSplit(message) {
        return !!message.querySelector(':scope > .catalog-maintenance-message__label');
    }

    function splitMessage(message) {
        if (!message || isSplit(message)) {
            return;
        }

        var text = (message.textContent || '').trim();
        var separator = text.indexOf(':');
        var label = separator >= 0 ? text.slice(0, separator + 1).trim() : text;
        var detail = separator >= 0 ? text.slice(separator + 1).trim() : '';

        message.textContent = '';
        var labelElement = document.createElement('span');
        labelElement.className = 'catalog-maintenance-message__label';
        labelElement.textContent = label;
        message.appendChild(labelElement);

        var detailElement = document.createElement('span');
        detailElement.className = 'catalog-maintenance-message__detail';
        detailElement.textContent = detail || ' ';
        message.appendChild(detailElement);
    }

    function prepareMessage(message) {
        if (message) {
            splitMessage(message);
        }
    }

    function processNode(node) {
        if (!(node instanceof Element)) return;
        if (node.matches && node.matches(messageSelectors)) {
            prepareMessage(node);
        }
        node.querySelectorAll && node.querySelectorAll(messageSelectors).forEach(prepareMessage);
    }

    function observeMessages() {
        document.querySelectorAll(messageSelectors).forEach(prepareMessage);

        var observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                if (mutation.type === 'childList') {
                    processNode(mutation.target);
                    mutation.addedNodes.forEach(processNode);
                }
            });
        });
        observer.observe(document.documentElement, {
            childList: true,
            subtree: true
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            addStyle();
            observeMessages();
        });
    } else {
        addStyle();
        observeMessages();
    }
})();
