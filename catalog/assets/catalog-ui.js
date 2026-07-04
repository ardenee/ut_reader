(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var dismiss = event.target.closest('[data-ui-dismiss]');
        if (!dismiss) return;

        var alert = dismiss.closest('.ui-alert');
        if (alert) alert.remove();
    });

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-ui-loading-form')) return;
        if (form.getAttribute('aria-busy') === 'true') {
            event.preventDefault();
            return;
        }

        form.setAttribute('aria-busy', 'true');
        form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (control) {
            control.disabled = true;
        });
    });

    function installSortableHeaderStyle() {
        var style = document.createElement('style');
        style.textContent = [
            '[data-sortable-table] th::after { content: none !important; display: none !important; }',
            '[data-sortable-table] th.is-sort-ascending::after { content: "▲" !important; display: inline-block !important; margin-left: 7px !important; color: var(--blue) !important; font-size: 11px !important; opacity: 1 !important; }',
            '[data-sortable-table] th.is-sort-descending::after { content: "▼" !important; display: inline-block !important; margin-left: 7px !important; color: var(--blue) !important; font-size: 11px !important; opacity: 1 !important; }'
        ].join('\n');
        document.head.appendChild(style);
    }

    function initExamineReferenceEmphasis() {
        var packageTables = document.getElementById('package-tables');
        if (!packageTables) return;

        var style = document.createElement('style');
        style.textContent = [
            '#package-tables .examine-table-region > table { width: max-content !important; min-width: 0 !important; }',
            '#package-tables .examine-imports-table { width: max-content !important; min-width: 0 !important; }',
            '#package-tables .examine-imports-table th, #package-tables .examine-imports-table td { width: auto !important; }',
            '#package-tables .examine-imports-table td:nth-child(3), #package-tables .examine-imports-table td:nth-child(4), #package-tables .examine-imports-table td:nth-child(5), #package-tables .examine-imports-table td:nth-child(6), #package-tables .examine-imports-table td:nth-child(7), #package-tables .examine-imports-table td:nth-child(8), #package-tables .examine-imports-table td:nth-child(9), #package-tables .examine-imports-table td:nth-child(3) *, #package-tables .examine-imports-table td:nth-child(4) *, #package-tables .examine-imports-table td:nth-child(5) *, #package-tables .examine-imports-table td:nth-child(6) *, #package-tables .examine-imports-table td:nth-child(7) *, #package-tables .examine-imports-table td:nth-child(8) *, #package-tables .examine-imports-table td:nth-child(9) * { white-space: nowrap !important; word-break: normal !important; overflow-wrap: normal !important; }',
            '#package-tables .examine-dependency-entry { display: flex !important; align-items: flex-start !important; gap: 6px !important; margin: 0 0 5px !important; padding: 0 !important; white-space: nowrap !important; }',
            '#package-tables .examine-dependency-entry .examine-dependency-flag { display: inline-block !important; flex: 0 0 auto !important; margin: 0 !important; padding: 2px 8px !important; }',
            '#package-tables .examine-dependency-detail { display: inline-block !important; flex: 0 1 auto !important; min-width: 0 !important; margin: 0 !important; padding: 0 !important; white-space: nowrap !important; }',
            '#package-tables .examine-dependency-detail .mono { display: inline !important; margin: 0 !important; padding: 0 !important; }',
            '#package-tables .examine-dependency-detail a { text-decoration: underline; }',
            '#package-tables tr.is-reference-target > td.examine-reference-target-cell, #package-tables tr.is-reference-target > th.examine-reference-target-cell { background: #6e5616 !important; color: #fff9d8 !important; box-shadow: inset 0 0 0 2px #f6c453, inset 5px 0 0 #f6c453 !important; }',
            '#package-tables tr.is-reference-target > td.examine-reference-target-cell a { color: #fff9d8 !important; text-decoration: underline; }'
        ].join('\n');
        document.head.appendChild(style);

        function splitDependencyEntries() {
            packageTables.querySelectorAll('.examine-dependency-entry').forEach(function (entry) {
                if (entry.dataset.dependencySplit === '1') return;

                var flag = entry.querySelector('.examine-dependency-flag');
                var detail = entry.querySelector('.examine-dependency-detail');
                if (!flag) return;

                var parts = [];
                var pendingText = '';
                if (detail) {
                    Array.from(detail.childNodes).forEach(function (node) {
                        if (node.nodeType === Node.TEXT_NODE) {
                            pendingText += node.textContent || '';
                            return;
                        }
                        if (node.nodeType !== Node.ELEMENT_NODE) return;

                        parts.push({
                            prefix: pendingText.trim() || '→',
                            node: node
                        });
                        pendingText = '';
                    });
                }

                var parent = entry.parentNode;
                if (!parent) return;

                var entries = [];
                if (!parts.length) {
                    var noTargetEntry = document.createElement('div');
                    noTargetEntry.className = entry.className;
                    noTargetEntry.dataset.dependencySplit = '1';
                    noTargetEntry.appendChild(flag.cloneNode(true));
                    entries.push(noTargetEntry);
                } else {
                    parts.forEach(function (part) {
                        var line = document.createElement('div');
                        line.className = entry.className;
                        line.dataset.dependencySplit = '1';

                        var lineFlag = flag.cloneNode(true);
                        lineFlag.classList.remove('examine-dependency-blob');
                        lineFlag.removeAttribute('data-examine-blob');
                        line.appendChild(lineFlag);

                        var lineDetail = document.createElement('span');
                        lineDetail.className = 'examine-dependency-detail';
                        lineDetail.appendChild(document.createTextNode(part.prefix + ' '));
                        lineDetail.appendChild(part.node);
                        line.appendChild(lineDetail);
                        entries.push(line);
                    });
                }

                entries.forEach(function (line) {
                    parent.insertBefore(line, entry);
                });
                entry.remove();
            });
        }

        function normalize(value) {
            return (value || '').replace(/\s+/g, ' ').trim().toLocaleLowerCase();
        }

        function clearTargetCells() {
            packageTables.querySelectorAll('.examine-reference-target-cell').forEach(function (cell) {
                cell.classList.remove('examine-reference-target-cell');
            });
        }

        function targetIdsFor(link) {
            if (link.dataset.referenceTargets) {
                try {
                    var decoded = JSON.parse(link.dataset.referenceTargets);
                    if (Array.isArray(decoded)) return decoded.filter(function (value) { return typeof value === 'string'; });
                } catch (error) {
                    return [];
                }
            }

            var href = link.getAttribute('href') || '';
            if (href.charAt(0) === '#') {
                return [decodeURIComponent(href.slice(1))];
            }
            return [];
        }

        function referenceValueFor(link) {
            if (link.dataset.referenceValue) return link.dataset.referenceValue;

            if (link.classList.contains('name-usage-link')) {
                var nameRow = link.closest('tr');
                if (nameRow && nameRow.cells.length > 1) {
                    return nameRow.cells[1].textContent || '';
                }
            }

            return link.textContent || '';
        }

        function targetCell(row, targetId, referenceValue) {
            var expected = normalize(referenceValue);
            var cells = Array.from(row.cells || []);
            if (expected) {
                var exact = cells.find(function (cell) {
                    return normalize(cell.textContent) === expected;
                });
                if (exact) return exact;
            }

            if (targetId.indexOf('name-') === 0) return cells[1] || cells[0] || null;
            if (targetId.indexOf('import-') === 0 || targetId.indexOf('export-') === 0) return cells[1] || cells[0] || null;
            return cells[0] || null;
        }

        function emphasizeTargets(link) {
            var targetIds = targetIdsFor(link);
            if (!targetIds.length) return;

            var referenceValue = referenceValueFor(link);
            window.setTimeout(function () {
                clearTargetCells();
                targetIds.forEach(function (targetId) {
                    var target = document.getElementById(targetId);
                    var row = target && (target.matches('tr') ? target : target.closest('tr'));
                    if (!row) return;
                    row.classList.add('is-reference-target');
                    var cell = targetCell(row, targetId, referenceValue);
                    if (cell) cell.classList.add('examine-reference-target-cell');
                });
            }, 150);
        }

        document.addEventListener('click', function (event) {
            var tab = event.target.closest('[data-examine-tab]');
            if (tab) {
                clearTargetCells();
                return;
            }

            var reference = event.target.closest('#package-tables a.xref[href^="#"], #package-tables a[data-reference-targets]');
            if (reference) emphasizeTargets(reference);
        }, true);

        window.addEventListener('hashchange', function () {
            var hash = decodeURIComponent(window.location.hash.replace(/^#/, ''));
            if (hash && hash.indexOf('tab-') !== 0) {
                emphasizeTargets({
                    dataset: {},
                    getAttribute: function () { return '#' + hash; },
                    classList: { contains: function () { return false; } },
                    textContent: ''
                });
            } else {
                clearTargetCells();
            }
        });

        splitDependencyEntries();
        window.setTimeout(splitDependencyEntries, 0);

        var initialHash = decodeURIComponent(window.location.hash.replace(/^#/, ''));
        if (initialHash && initialHash.indexOf('tab-') !== 0) {
            emphasizeTargets({
                dataset: {},
                getAttribute: function () { return '#' + initialHash; },
                classList: { contains: function () { return false; } },
                textContent: ''
            });
        }
    }

    installSortableHeaderStyle();
    initExamineReferenceEmphasis();
})();
