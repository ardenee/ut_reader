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
            '.pak-source-download-icon, .catalog-download-icon { display: inline-grid; place-items: center; width: 28px; height: 28px; padding: 4px; border: 1px solid var(--line2); border-radius: 7px; background: rgba(118, 169, 255, .08); color: var(--blue); vertical-align: middle; }',
            '.pak-source-download-icon:hover, .catalog-download-icon:hover { background: rgba(118, 169, 255, .18); text-decoration: none; }',
            '.pak-source-download-icon img, .catalog-download-icon img { display: block; width: 18px; height: 18px; }',
            'main > .grid + .card, main > .grid + .two-col, main > .grid + .ui-section { margin-top: 16px; }',
            '#unverified-bulk-form { padding: 18px; margin-bottom: 16px; border: 1px solid var(--line); border-radius: 18px; background: linear-gradient(180deg, rgba(255, 255, 255, .045), rgba(255, 255, 255, .025)); box-shadow: var(--shadow); }',
            '#unverified-bulk-form .uv-actions { margin-top: 0; }',
            'td:has(> form[style*="display:inline"]) { white-space: nowrap; }',
            'td > form[style*="display:inline"] { display: inline-flex !important; align-items: center; margin: 0; vertical-align: middle; }',
            'td > form[style*="display:inline"] button { margin-bottom: 0; }'
        ].join('\n');
        document.head.appendChild(style);
    }

    function useDownloadIcon(link, label) {
        if (!link || link.dataset.catalogDownloadIcon === '1') return;
        link.classList.remove('ui-icon-action', 'ui-icon-action--primary', 'ui-icon-action--secondary', 'ui-icon-action--sm', 'ui-icon-action--md');
        link.classList.add('catalog-download-icon');
        link.title = label;
        link.setAttribute('aria-label', label);
        link.textContent = '';
        var image = document.createElement('img');
        image.src = iconUrl;
        image.alt = '';
        image.width = 18;
        image.height = 18;
        link.appendChild(image);
        link.dataset.catalogDownloadIcon = '1';
    }

    function fixStandardDownloadIcons(root) {
        if (!root || !root.querySelectorAll) return;
        if (page === 'game-paks.php' || page === 'pak-info.php') {
            root.querySelectorAll('a[href*="pak-download.php"]').forEach(function (link) {
                useDownloadIcon(link, 'Download original PAK');
            });
        }
        if (page === 'download-info.php') {
            root.querySelectorAll('a[href*="download.php?id="]').forEach(function (link) {
                useDownloadIcon(link, link.getAttribute('aria-label') || link.title || 'Download file');
            });
        }
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
    fixStandardDownloadIcons(document);
    fixPakSourceCards(document);

    var observer = new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
            mutation.addedNodes.forEach(function (node) {
                if (node.nodeType === Node.ELEMENT_NODE) {
                    fixStandardDownloadIcons(node);
                    fixPakSourceCards(node);
                }
            });
        });
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });
})();
