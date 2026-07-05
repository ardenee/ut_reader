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

    function addStyle(cssRules) {
        var style = document.createElement('style');
        style.textContent = cssRules.join('\n');
        document.head.appendChild(style);
    }

    function installSortableHeaderStyle() {
        addStyle([
            '[data-sortable-table] th::after { content: none !important; display: none !important; }',
            '[data-sortable-table] th.is-sort-ascending::after { content: "▲" !important; display: inline-block !important; margin-left: 7px !important; color: var(--blue) !important; font-size: 11px !important; opacity: 1 !important; }',
            '[data-sortable-table] th.is-sort-descending::after { content: "▼" !important; display: inline-block !important; margin-left: 7px !important; color: var(--blue) !important; font-size: 11px !important; opacity: 1 !important; }'
        ]);
    }

    function initExaminePage() {
        var packageTables = document.getElementById('package-tables');
        if (!packageTables) return;

        addStyle([
            '#package-tables .examine-table-region { width: 100% !important; }',
            '#package-tables .examine-table-region > table { width: 100% !important; min-width: 100% !important; max-width: none !important; table-layout: auto !important; }',
            '#package-tables .examine-imports-table, #package-tables .examine-exports-table { width: 100% !important; min-width: 100% !important; max-width: none !important; }',
            '#package-tables .examine-imports-table th, #package-tables .examine-imports-table td, #package-tables .examine-exports-table th, #package-tables .examine-exports-table td { width: auto !important; }',
            '#package-tables .examine-imports-table th:nth-child(n+2), #package-tables .examine-imports-table td:nth-child(n+2), #package-tables .examine-imports-table th:nth-child(n+2) *, #package-tables .examine-imports-table td:nth-child(n+2) * { white-space: nowrap !important; word-break: normal !important; overflow-wrap: normal !important; }',
            '#package-tables .examine-dependency-entry { display: flex !important; align-items: flex-start !important; gap: 6px !important; margin: 0 0 5px !important; padding: 0 !important; white-space: nowrap !important; }',
            '#package-tables .examine-dependency-entry .examine-dependency-flag { display: inline-block !important; flex: 0 0 auto !important; margin: 0 !important; padding: 2px 8px !important; }',
            '#package-tables .examine-dependency-detail { display: inline-block !important; flex: 0 1 auto !important; min-width: 0 !important; margin: 0 !important; padding: 0 !important; white-space: nowrap !important; }',
            '#package-tables .examine-dependency-detail .mono { display: inline !important; margin: 0 !important; padding: 0 !important; }',
            '#package-tables .examine-dependency-detail a { text-decoration: underline; }',
            '#package-tables tr.is-reference-target > td { background: rgba(246, 196, 83, .18) !important; box-shadow: inset 4px 0 0 var(--amber); border-top: 1px solid rgba(246, 196, 83, .55); border-bottom: 1px solid rgba(246, 196, 83, .55); }',
            '#package-tables tr.is-reference-target > td.examine-reference-target-cell { background: #6e5616 !important; color: #fff9d8 !important; box-shadow: inset 0 0 0 2px #f6c453, inset 5px 0 0 #f6c453 !important; }',
            '#package-tables tr.is-reference-target > td.examine-reference-target-cell a { color: #fff9d8 !important; text-decoration: underline; }'
        ]);

        function normalize(value) {
            return (value || '').replace(/\s+/g, ' ').trim().toLocaleLowerCase();
        }

        function bindSorting(table) {
            var headerRow = table.tHead && table.tHead.rows.length ? table.tHead.rows[0] : null;
            var body = table.tBodies.length ? table.tBodies[0] : null;
            if (!headerRow || !body || table.dataset.catalogSortBound === '1') return;

            var activeIndex = -1;
            var ascending = true;
            Array.from(headerRow.cells).forEach(function (header, index) {
                header.tabIndex = 0;
                header.setAttribute('role', 'button');
                header.setAttribute('title', 'Click to sort ascending. Click again to sort descending.');
                header.setAttribute('aria-label', header.textContent.trim() + '. Click to sort this table.');

                function sortByThisHeader() {
                    if (activeIndex === index) {
                        ascending = !ascending;
                    } else {
                        activeIndex = index;
                        ascending = true;
                    }

                    Array.from(body.rows).sort(function (leftRow, rightRow) {
                        var left = leftRow.cells[index] ? (leftRow.cells[index].dataset.sortValue || leftRow.cells[index].textContent || '').trim() : '';
                        var right = rightRow.cells[index] ? (rightRow.cells[index].dataset.sortValue || rightRow.cells[index].textContent || '').trim() : '';
                        var numeric = /^-?\d+(?:\.\d+)?$/;
                        var comparison = numeric.test(left) && numeric.test(right)
                            ? Number(left) - Number(right)
                            : left.localeCompare(right, undefined, { numeric: true, sensitivity: 'base' });
                        return ascending ? comparison : -comparison;
                    }).forEach(function (row) {
                        body.appendChild(row);
                    });

                    Array.from(headerRow.cells).forEach(function (otherHeader, otherIndex) {
                        otherHeader.classList.remove('is-sort-ascending', 'is-sort-descending');
                        otherHeader.removeAttribute('aria-sort');
                        if (otherIndex === index) {
                            otherHeader.classList.add(ascending ? 'is-sort-ascending' : 'is-sort-descending');
                            otherHeader.setAttribute('aria-sort', ascending ? 'ascending' : 'descending');
                        }
                    });
                }

                header.addEventListener('click', sortByThisHeader);
                header.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        sortByThisHeader();
                    }
                });
            });
            table.dataset.catalogSortBound = '1';
        }

        function movePackageRefsToIndexTooltips() {
            packageTables.querySelectorAll('.examine-imports-table, .examine-exports-table').forEach(function (table) {
                if (table.dataset.packageRefMoved === '1') return;

                Array.from(table.rows).forEach(function (row, rowIndex) {
                    if (row.cells.length < 2) return;
                    if (rowIndex === 0) {
                        row.deleteCell(1);
                        return;
                    }

                    var indexCell = row.cells[0];
                    var packageRef = (row.cells[1].textContent || '').trim();
                    var tooltip = 'Package ref: ' + packageRef;
                    indexCell.setAttribute('title', tooltip);
                    var indexLink = indexCell.querySelector('a');
                    if (indexLink) {
                        indexLink.setAttribute('title', tooltip);
                        indexLink.setAttribute('aria-label', (indexLink.textContent || '').trim() + '. ' + tooltip);
                    }
                    row.deleteCell(1);
                });

                var oldHead = table.tHead;
                if (oldHead) {
                    table.replaceChild(oldHead.cloneNode(true), oldHead);
                }
                table.dataset.packageRefMoved = '1';
                bindSorting(table);
            });
        }

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
                        parts.push({ prefix: pendingText.trim() || '→', node: node });
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
                        line.appendChild(flag.cloneNode(true));
                        var lineDetail = document.createElement('span');
                        lineDetail.className = 'examine-dependency-detail';
                        lineDetail.appendChild(document.createTextNode(part.prefix + ' '));
                        lineDetail.appendChild(part.node);
                        line.appendChild(lineDetail);
                        entries.push(line);
                    });
                }
                entries.forEach(function (line) { parent.insertBefore(line, entry); });
                entry.remove();
            });
        }

        function panels() {
            return Array.from(packageTables.querySelectorAll('[data-examine-panel]'));
        }

        function showPanelForTarget(target) {
            var panel = target ? target.closest('[data-examine-panel]') : null;
            if (!panel) return;
            var tabName = panel.dataset.examinePanel;
            panels().forEach(function (candidate) {
                candidate.hidden = candidate !== panel;
            });
            packageTables.querySelectorAll('[data-examine-tab]').forEach(function (tab) {
                var active = tab.dataset.examineTab === tabName;
                tab.classList.toggle('is-active', active);
                tab.setAttribute('aria-selected', active ? 'true' : 'false');
            });
        }

        function clearReferenceHighlights() {
            packageTables.querySelectorAll('.is-reference-target').forEach(function (row) {
                row.classList.remove('is-reference-target');
            });
            packageTables.querySelectorAll('.examine-reference-target-cell').forEach(function (cell) {
                cell.classList.remove('examine-reference-target-cell');
            });
        }

        function targetIdsFor(link) {
            if (link.dataset.referenceTargets) {
                try {
                    var list = JSON.parse(link.dataset.referenceTargets);
                    return Array.isArray(list) ? list.filter(function (value) { return typeof value === 'string'; }) : [];
                } catch (error) {
                    return [];
                }
            }
            var href = link.getAttribute('href') || '';
            return href.charAt(0) === '#' ? [decodeURIComponent(href.slice(1))] : [];
        }

        function signedPackageRef(link) {
            var href = link.getAttribute('href') || '';
            var value = (link.textContent || '').trim();
            return /^-?\d+$/.test(value) && /^#(?:import|export)-\d+$/.test(href);
        }

        function sourceValue(link) {
            if (link.classList.contains('name-usage-link')) {
                var nameRow = link.closest('tr');
                return nameRow && nameRow.cells.length > 1 ? (nameRow.cells[1].textContent || '') : '';
            }
            return link.textContent || '';
        }

        function destinationCell(row, targetId, referenceValue, isSignedReference) {
            var cells = Array.from(row.cells || []);
            if (isSignedReference || targetId.indexOf('import-') === 0 || targetId.indexOf('export-') === 0 && !referenceValue) {
                return cells[0] || null;
            }
            var expected = normalize(referenceValue);
            if (expected) {
                var exact = cells.find(function (cell) {
                    return normalize(cell.textContent) === expected;
                });
                if (exact) return exact;
            }
            return targetId.indexOf('name-') === 0 ? (cells[1] || cells[0] || null) : (cells[0] || null);
        }

        function followLocalReference(link) {
            var targetIds = targetIdsFor(link);
            if (!targetIds.length) return;

            var signed = signedPackageRef(link);
            var referenceValue = signed ? '' : sourceValue(link);
            var targetRows = targetIds.map(function (targetId) {
                var target = document.getElementById(targetId);
                return target && { id: targetId, target: target, row: target.matches('tr') ? target : target.closest('tr') };
            }).filter(function (item) { return item && item.row; });
            if (!targetRows.length) return;

            clearReferenceHighlights();
            showPanelForTarget(targetRows[0].target);
            targetRows.forEach(function (item) {
                item.row.classList.add('is-reference-target');
                var cell = destinationCell(item.row, item.id, referenceValue, signed);
                if (cell) cell.classList.add('examine-reference-target-cell');
            });

            window.history.pushState(null, '', '#' + targetRows[0].id);
            window.setTimeout(function () {
                targetRows[0].target.scrollIntoView({ block: 'center' });
            }, 0);
        }

        document.addEventListener('click', function (event) {
            var reference = event.target.closest('#package-tables a.xref[href^="#"], #package-tables a[data-reference-targets]');
            if (!reference) return;

            event.preventDefault();
            event.stopImmediatePropagation();
            followLocalReference(reference);
        }, true);

        document.addEventListener('click', function (event) {
            var tab = event.target.closest('#package-tables [data-examine-tab]');
            if (tab) clearReferenceHighlights();
        }, true);

        window.addEventListener('hashchange', function () {
            var id = decodeURIComponent(window.location.hash.replace(/^#/, ''));
            if (!id || id.indexOf('tab-') === 0) return;
            var target = document.getElementById(id);
            if (!target) return;
            clearReferenceHighlights();
            showPanelForTarget(target);
            var row = target.matches('tr') ? target : target.closest('tr');
            if (row) {
                row.classList.add('is-reference-target');
                var indexCell = row.cells && row.cells[0];
                if (indexCell) indexCell.classList.add('examine-reference-target-cell');
            }
        });

        packageTables.querySelectorAll('table[data-sortable-table]').forEach(bindSorting);
        movePackageRefsToIndexTooltips();
        splitDependencyEntries();
        window.setTimeout(function () {
            movePackageRefsToIndexTooltips();
            splitDependencyEntries();
        }, 0);
    }

    installSortableHeaderStyle();
    initExaminePage();
})();
