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

    function initExamineReferenceEmphasis() {
        var packageTables = document.getElementById('package-tables');
        if (!packageTables) return;

        var style = document.createElement('style');
        style.textContent = [
            '#package-tables .examine-imports-table { min-width: 1750px !important; }',
            '#package-tables .examine-imports-table td:last-child { min-width: 330px !important; }',
            '#package-tables .examine-dependency-entry { display: block !important; margin: 0 0 4px !important; padding: 0 !important; white-space: nowrap !important; }',
            '#package-tables .examine-dependency-entry .examine-dependency-flag { display: inline-block !important; margin: 0 5px 0 0 !important; padding: 2px 8px !important; vertical-align: baseline !important; }',
            '#package-tables .examine-dependency-detail { display: inline !important; flex: none !important; min-width: 0 !important; margin: 0 !important; padding: 0 !important; white-space: nowrap !important; }',
            '#package-tables .examine-dependency-detail > span { display: inline !important; margin: 0 !important; padding: 0 !important; }',
            '#package-tables tr.is-reference-target > td.examine-reference-target-cell, #package-tables tr.is-reference-target > th.examine-reference-target-cell { background: #66511b !important; color: #fff6d0 !important; box-shadow: inset 0 0 0 2px #f6c453, inset 5px 0 0 #f6c453 !important; }',
            '#package-tables tr.is-reference-target > td.examine-reference-target-cell a { color: #ffffff !important; text-decoration: underline; }'
        ].join('\n');
        document.head.appendChild(style);

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
                    var cell = targetCell(row, targetId, referenceValue);
                    if (cell) cell.classList.add('examine-reference-target-cell');
                });
            }, 0);
        }

        document.addEventListener('click', function (event) {
            var tab = event.target.closest('[data-examine-tab]');
            if (tab) {
                clearTargetCells();
                return;
            }

            var reference = event.target.closest('#package-tables a.xref[href^="#"], #package-tables a[data-reference-targets]');
            if (reference) emphasizeTargets(reference);
        });

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

    initExamineReferenceEmphasis();
})();
