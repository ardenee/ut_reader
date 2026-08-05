(function () {
    'use strict';

    function addStyles() {
        if (document.getElementById('catalog-global-table-sort-styles')) return;
        var style = document.createElement('style');
        style.id = 'catalog-global-table-sort-styles';
        style.textContent = [
            'table[data-sortable-table] th.catalog-sortable-column { cursor: pointer; user-select: none; }',
            'table[data-sortable-table] th.catalog-sortable-column:hover { background: rgba(118, 169, 255, .12); }',
            'table[data-sortable-table] th.catalog-sortable-column:focus-visible { outline: 2px solid var(--blue); outline-offset: -2px; }',
            'table[data-sortable-table] th.catalog-sortable-column::after { content: "↕"; display: inline-block; margin-left: 7px; font-size: 10px; opacity: .45; }',
            'table[data-sortable-table] th.catalog-sortable-column.is-sort-ascending::after { content: "▲"; opacity: 1; color: var(--blue); }',
            'table[data-sortable-table] th.catalog-sortable-column.is-sort-descending::after { content: "▼"; opacity: 1; color: var(--blue); }'
        ].join('\n');
        document.head.appendChild(style);
    }

    function disabled(table) {
        return table.dataset.tableSort === 'false'
            || table.getAttribute('data-sortable-table') === 'false'
            || table.classList.contains('no-table-sort');
    }

    function headerRowFor(table) {
        if (table.tHead) {
            if (table.tHead.rows.length !== 1) return null;
            return table.tHead.rows[0] || null;
        }

        var first = table.rows.length ? table.rows[0] : null;
        if (!first || first.cells.length < 2) return null;
        return Array.from(first.cells).every(function (cell) {
            return cell.tagName === 'TH' && cell.colSpan === 1 && cell.rowSpan === 1;
        }) ? first : null;
    }

    function rowGroupFor(table, headerRow) {
        if (headerRow.parentElement && headerRow.parentElement.tagName === 'TBODY') {
            return headerRow.parentElement;
        }
        return table.tBodies.length ? table.tBodies[0] : null;
    }

    function sortableRows(table, headerRow) {
        var group = rowGroupFor(table, headerRow);
        if (!group) return [];

        var rows = Array.from(group.rows);
        var start = headerRow.parentElement === group ? rows.indexOf(headerRow) + 1 : 0;
        var expectedCells = headerRow.cells.length;
        var result = [];

        for (var index = Math.max(0, start); index < rows.length; index += 1) {
            var row = rows[index];
            var valid = row.cells.length === expectedCells
                && !row.hasAttribute('data-sort-fixed')
                && !row.classList.contains('no-sort-row')
                && Array.from(row.cells).every(function (cell) {
                    return cell.tagName === 'TD' && cell.colSpan === 1 && cell.rowSpan === 1;
                });

            if (!valid) {
                if (result.length > 0) break;
                continue;
            }
            result.push(row);
        }

        return result;
    }

    function textValue(cell) {
        var explicit = cell.getAttribute('data-sort-value');
        if (explicit !== null) return explicit.trim();
        var time = cell.querySelector('time[datetime]');
        if (time) return (time.getAttribute('datetime') || '').trim();
        return (cell.textContent || '').replace(/\s+/g, ' ').trim();
    }

    function byteValue(value) {
        var match = value.match(/^(-?[\d,.]+)\s*(B|KB|MB|GB|TB|PB|KIB|MIB|GIB|TIB|PIB)$/i);
        if (!match) return null;
        var amount = Number(match[1].replace(/,/g, ''));
        if (!Number.isFinite(amount)) return null;
        var unit = match[2].toUpperCase();
        var binary = unit.indexOf('I') >= 0;
        var power = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'].indexOf(unit.replace('I', ''));
        return power < 0 ? null : amount * Math.pow(binary ? 1024 : 1000, power);
    }

    function numericValue(value) {
        var normalized = value.replace(/[\u00a0\s]/g, '').replace(/^([€$£¥])/, '').replace(/%$/, '');
        var negative = /^\(.*\)$/.test(normalized);
        if (negative) normalized = normalized.slice(1, -1);
        if (!/^-?[\d,]+(?:\.\d+)?$/.test(normalized)) return null;
        var number = Number(normalized.replace(/,/g, ''));
        if (!Number.isFinite(number)) return null;
        return negative ? -number : number;
    }

    function durationValue(value) {
        if (/^\d{1,3}:\d{2}(?::\d{2})?$/.test(value)) {
            var parts = value.split(':').map(Number);
            return parts.length === 3
                ? (parts[0] * 3600) + (parts[1] * 60) + parts[2]
                : (parts[0] * 60) + parts[1];
        }
        return null;
    }

    function parsedValue(cell, header) {
        var raw = textValue(cell);
        var value = raw.replace(/\s+/g, ' ').trim();
        if (value === '') return { empty: true, type: 'text', value: '' };

        var requestedType = (cell.dataset.sortType || header.dataset.sortType || '').toLowerCase();
        var bytes = requestedType === 'text' ? null : byteValue(value);
        if (bytes !== null) return { empty: false, type: 'number', value: bytes };

        var duration = requestedType === 'text' ? null : durationValue(value);
        if (duration !== null) return { empty: false, type: 'number', value: duration };

        var number = requestedType === 'text' ? null : numericValue(value);
        if (number !== null) return { empty: false, type: 'number', value: number };

        if (requestedType === 'date' || /^\d{4}-\d{2}-\d{2}(?:[T\s].*)?$/.test(value)) {
            var timestamp = Date.parse(value);
            if (!Number.isNaN(timestamp)) return { empty: false, type: 'number', value: timestamp };
        }

        return { empty: false, type: 'text', value: value.toLocaleLowerCase() };
    }

    function comparison(left, right, header) {
        var a = parsedValue(left, header);
        var b = parsedValue(right, header);
        if (a.empty || b.empty) {
            if (a.empty && b.empty) return 0;
            return a.empty ? 1 : -1;
        }
        if (a.type === 'number' && b.type === 'number') return a.value - b.value;
        return String(a.value).localeCompare(String(b.value), undefined, {
            numeric: true,
            sensitivity: 'base'
        });
    }

    function updateHeaders(headerRow, activeIndex, ascending) {
        Array.from(headerRow.cells).forEach(function (header, index) {
            header.classList.remove('is-sort-ascending', 'is-sort-descending');
            header.removeAttribute('aria-sort');
            if (index === activeIndex) {
                header.classList.add(ascending ? 'is-sort-ascending' : 'is-sort-descending');
                header.setAttribute('aria-sort', ascending ? 'ascending' : 'descending');
            }
        });
    }

    function sortTable(table, headerRow, columnIndex) {
        var rows = sortableRows(table, headerRow);
        if (rows.length < 1) return;

        var previousIndex = Number(table.dataset.globalSortIndex || -1);
        var ascending = previousIndex === columnIndex
            ? table.dataset.globalSortDirection !== 'ascending'
            : true;
        table.dataset.globalSortIndex = String(columnIndex);
        table.dataset.globalSortDirection = ascending ? 'ascending' : 'descending';

        var header = headerRow.cells[columnIndex];
        var ordered = rows.map(function (row, originalIndex) {
            return { row: row, originalIndex: originalIndex };
        }).sort(function (left, right) {
            var result = comparison(left.row.cells[columnIndex], right.row.cells[columnIndex], header);
            if (result === 0) result = left.originalIndex - right.originalIndex;
            return ascending ? result : -result;
        });

        var anchor = rows[rows.length - 1].nextSibling;
        var parent = rows[0].parentNode;
        var fragment = document.createDocumentFragment();
        ordered.forEach(function (entry) {
            fragment.appendChild(entry.row);
        });
        parent.insertBefore(fragment, anchor);
        updateHeaders(headerRow, columnIndex, ascending);
    }

    function bind(table) {
        if (!(table instanceof HTMLTableElement) || disabled(table)) return;
        var headerRow = headerRowFor(table);
        if (!headerRow || sortableRows(table, headerRow).length < 1) return;

        if (table.dataset.catalogSortBound === '1' && table.dataset.packageRefMoved !== '1') {
            return;
        }
        if (table.dataset.globalTableSortBound === '1' && headerRow.dataset.globalTableSortHeader === '1') {
            return;
        }

        table.setAttribute('data-sortable-table', '');
        table.dataset.globalTableSortBound = '1';
        headerRow.dataset.globalTableSortHeader = '1';

        Array.from(headerRow.cells).forEach(function (header, index) {
            if (header.dataset.sortableColumn === 'false' || header.classList.contains('no-sort')) return;
            header.classList.add('catalog-sortable-column');
            header.tabIndex = 0;
            header.setAttribute('role', 'button');
            header.setAttribute('title', 'Click to sort ascending. Click again to sort descending.');
            header.setAttribute('aria-label', (header.textContent || '').trim() + '. Click to sort this table.');

            header.addEventListener('click', function () {
                sortTable(table, headerRow, index);
            });
            header.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter' && event.key !== ' ') return;
                event.preventDefault();
                sortTable(table, headerRow, index);
            });
        });
    }

    function scan(root) {
        if (root instanceof HTMLTableElement) bind(root);
        if (!(root instanceof Element || root instanceof Document)) return;
        root.querySelectorAll('table').forEach(bind);
    }

    addStyles();
    scan(document);

    if (document.body && typeof MutationObserver !== 'undefined') {
        new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                mutation.addedNodes.forEach(function (node) {
                    if (node instanceof Element) scan(node);
                });
            });
        }).observe(document.body, { childList: true, subtree: true });
    }
})();
