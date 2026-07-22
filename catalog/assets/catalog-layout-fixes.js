(function () {
    'use strict';

    var script = document.currentScript;
    var iconUrl = script && script.src
        ? new URL('download-icon.svg', script.src).toString()
        : 'assets/download-icon.svg';
    var page = (window.location.pathname.split('/').pop() || '').toLowerCase();

    function addStyle() {
        var style = document.createElement('style');
        style.textContent = [
            '#game-files-table th:first-child, #game-files-table td:first-child { white-space: normal !important; overflow-wrap: anywhere !important; word-break: break-word !important; }',
            '#game-files-table .game-files-package { width: auto !important; max-width: 30ch; }',
            '.file-info-scan-notes { display: block; width: 100%; max-width: 100%; margin: 0; white-space: pre-wrap !important; overflow-wrap: anywhere !important; word-break: break-word !important; }',
            '.pak-info-table-region th:first-child, .pak-info-table-region td:first-child { white-space: nowrap !important; overflow-wrap: normal !important; word-break: normal !important; }',
            '.file-pak-source-card .pak-source-actions { width: 1%; white-space: nowrap; text-align: center; }',
            '.pak-source-download-icon { display: inline-grid; place-items: center; width: 28px; height: 28px; padding: 4px; border: 1px solid var(--line2); border-radius: 7px; background: rgba(118, 169, 255, .08); }',
            '.pak-source-download-icon:hover { background: rgba(118, 169, 255, .18); text-decoration: none; }',
            '.pak-source-download-icon img { display: block; width: 18px; height: 18px; }'
        ].join('\n');
        document.head.appendChild(style);
    }

    function fixScanNotes() {
        if (page !== 'file-info.php') return;
        document.querySelectorAll('.card').forEach(function (card) {
            var heading = card.querySelector(':scope > h2');
            if (!heading || heading.textContent.trim().toLowerCase() !== 'scan notes') return;
            var notes = card.querySelector(':scope > pre');
            if (notes) notes.classList.add('file-info-scan-notes');
        });
    }

    function fixPakInfoTable() {
        if (page !== 'pak-info.php') return;
        document.querySelectorAll('.pak-info-table-region table').forEach(function (table) {
            if (!table.tHead || !table.tHead.rows.length) return;
            Array.from(table.tHead.rows[0].cells).forEach(function (header) {
                if (header.textContent.trim().toLowerCase() === 'database (n/i/e)') {
                    header.textContent = 'Database';
                    header.title = 'Names / Imports / Exports';
                }
            });
        });
    }

    function removeColumn(table, index) {
        if (index < 0) return;
        Array.from(table.rows).forEach(function (row) {
            if (row.cells.length > index) row.deleteCell(index);
        });
    }

    function fixPakSourceCard(card) {
        if (!card || card.dataset.catalogPakLayoutFixed === '1') return;
        var table = card.querySelector('table');
        if (!table || !table.tHead || !table.tHead.rows.length) return;

        var headers = Array.from(table.tHead.rows[0].cells);
        var importIndex = headers.findIndex(function (header) {
            return header.textContent.trim().toLowerCase() === 'import result';
        });
        removeColumn(table, importIndex);

        var currentHeaders = Array.from(table.tHead.rows[0].cells);
        var actionsIndex = currentHeaders.findIndex(function (header) {
            return header.textContent.trim().toLowerCase() === 'actions';
        });
        if (actionsIndex >= 0) {
            Array.from(table.tBodies).forEach(function (body) {
                Array.from(body.rows).forEach(function (row) {
                    var actions = row.cells[actionsIndex];
                    if (!actions) return;
                    actions.querySelectorAll('a[href*="pak-info.php"]').forEach(function (link) {
                        link.remove();
                    });
                    var download = actions.querySelector('a[href*="pak-download.php"]');
                    if (!download) return;
                    download.className = 'pak-source-download-icon';
                    download.title = 'Download original PAK';
                    download.setAttribute('aria-label', 'Download original PAK');
                    download.textContent = '';
                    var image = document.createElement('img');
                    image.src = iconUrl;
                    image.alt = '';
                    image.width = 18;
                    image.height = 18;
                    download.appendChild(image);
                });
            });
        }

        card.dataset.catalogPakLayoutFixed = '1';
    }

    function fixPakSourceCards(root) {
        if (page !== 'file-examine.php') return;
        if (root instanceof Element && root.matches('.file-pak-source-card')) {
            fixPakSourceCard(root);
        }
        if (root && root.querySelectorAll) {
            root.querySelectorAll('.file-pak-source-card').forEach(fixPakSourceCard);
        }
    }

    addStyle();
    fixScanNotes();
    fixPakInfoTable();
    fixPakSourceCards(document);

    var observer = new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
            mutation.addedNodes.forEach(function (node) {
                if (node.nodeType === Node.ELEMENT_NODE) {
                    fixPakSourceCards(node);
                }
            });
        });
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });
})();
