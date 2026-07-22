(function () {
    'use strict';

    if ((window.location.pathname.split('/').pop() || '').toLowerCase() !== 'duplicates.php') {
        return;
    }

    function removeColumn(table, index) {
        if (index < 0) return;
        Array.from(table.rows).forEach(function (row) {
            if (row.cells.length > index) row.deleteCell(index);
        });
    }

    function addStyles() {
        var style = document.createElement('style');
        style.textContent = [
            '.duplicates-wrap-cell, .duplicates-wrap-cell * { white-space: normal !important; overflow-wrap: anywhere !important; word-break: break-word !important; }',
            '.duplicates-file-type-stack { display: flex; flex-direction: column; align-items: flex-start; gap: 5px; }'
        ].join('\n');
        document.head.appendChild(style);
    }

    function updateTable(table) {
        if (!table.tHead || !table.tHead.rows.length) return;

        var headers = Array.from(table.tHead.rows[0].cells);
        function indexOf(label) {
            return headers.findIndex(function (header) {
                return header.textContent.trim().toLowerCase() === label;
            });
        }

        var retireIndex = indexOf('retire');
        var packageIndex = indexOf('package');
        var fileIndex = indexOf('file');
        var fileTypeIndex = indexOf('file type');
        var chunksIndex = indexOf('chunks');
        var sourcesIndex = indexOf('sources');

        Array.from(table.tBodies).forEach(function (body) {
            Array.from(body.rows).forEach(function (row) {
                if (packageIndex >= 0 && row.cells[packageIndex]) {
                    row.cells[packageIndex].classList.add('duplicates-wrap-cell');
                }
                if (fileIndex >= 0 && row.cells[fileIndex]) {
                    row.cells[fileIndex].classList.add('duplicates-wrap-cell');
                }
                if (fileTypeIndex >= 0 && chunksIndex >= 0 && row.cells[fileTypeIndex] && row.cells[chunksIndex]) {
                    var typeCell = row.cells[fileTypeIndex];
                    var chunkCell = row.cells[chunksIndex];
                    var stack = document.createElement('div');
                    stack.className = 'duplicates-file-type-stack';
                    while (typeCell.firstChild) stack.appendChild(typeCell.firstChild);
                    while (chunkCell.firstChild) stack.appendChild(chunkCell.firstChild);
                    typeCell.appendChild(stack);
                }
            });
        });

        [retireIndex, chunksIndex, sourcesIndex]
            .filter(function (index) { return index >= 0; })
            .sort(function (left, right) { return right - left; })
            .forEach(function (index) { removeColumn(table, index); });
    }

    function initialize() {
        var originalForm = document.getElementById('duplicates-page-form');
        if (!originalForm) return;

        addStyles();

        var form = originalForm.cloneNode(true);
        originalForm.replaceWith(form);
        form.action = 'duplicates-keep.php';
        form.removeAttribute('onsubmit');

        form.querySelectorAll('[data-canonical-radio]').forEach(function (radio) {
            radio.checked = false;
        });
        form.querySelectorAll('.duplicates-table').forEach(updateTable);

        document.querySelectorAll('.duplicates-submit-bar .muted').forEach(function (message) {
            message.textContent = 'Select Keep for each GUID group you want to resolve. Every other active file in that group will be retired.';
        });

        var help = document.querySelector('.duplicates-help p');
        if (help) {
            help.textContent = 'Keep selects the primary active catalog file for a GUID group. Every other active file with the same game and GUID is retired automatically, source locations are moved to the kept file, and resolved dependencies are redirected where possible.';
        }

        form.querySelectorAll('[data-duplicate-group] > p.muted').forEach(function (message) {
            message.textContent += ' Select one file to keep; all other active members of this GUID group are retired, including members outside the current page or filter.';
        });

        form.addEventListener('submit', function (event) {
            if (!form.querySelector('[data-canonical-radio]:checked')) {
                event.preventDefault();
                window.alert('Select at least one file to Keep.');
                return;
            }
            if (!window.confirm('Keep the selected primary file(s) and retire every other active file in those GUID group(s)?')) {
                event.preventDefault();
            }
        });
    }

    initialize();
})();
