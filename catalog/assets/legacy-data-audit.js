(function () {
    'use strict';

    var form = document.getElementById('legacy-audit-form');
    var gameSelect = document.getElementById('legacy-audit-game');
    var results = document.getElementById('legacy-audit-results');
    var summary = document.getElementById('legacy-audit-summary');
    if (!form || !gameSelect || !results || !summary || !window.CatalogLongJob) return;

    var endpoint = 'legacy-data-audit-api.php';

    function post(data) {
        return fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Accept': 'application/json'},
            body: data
        }).then(CatalogLongJob.parseJson).then(function (payload) {
            if (!payload.ok) throw new Error(payload.error || 'Audit request failed.');
            return payload;
        });
    }

    function severityLabel(result) {
        if (Number(result.error_count || 0) > 0) return 'error';
        if (Number(result.warning_count || 0) > 0) return 'warning';
        return 'clean';
    }

    function issueText(issue) {
        var rowIndex = issue.rowIndex === null || issue.rowIndex === undefined ? '' : ' #' + issue.rowIndex;
        var text = '[' + String(issue.severity || 'info').toUpperCase() + '] '
            + String(issue.table || 'catalog') + rowIndex + ': ' + String(issue.message || issue.code || 'issue');
        if (issue.expected !== null && issue.expected !== undefined) text += ' | expected=' + JSON.stringify(issue.expected);
        if (issue.actual !== null && issue.actual !== undefined) text += ' | actual=' + JSON.stringify(issue.actual);
        return text;
    }

    function renderRows(audits, failures) {
        results.textContent = '';
        if (!audits.length && !failures.length) {
            results.textContent = 'No files were returned for this game.';
            return;
        }

        var table = document.createElement('table');
        table.setAttribute('data-sortable-table', '');
        table.innerHTML = '<thead><tr><th>Status</th><th>Engine</th><th>Package</th><th>File</th><th>Names</th><th>Imports</th><th>Exports</th><th>Issues</th></tr></thead><tbody></tbody>';
        var body = table.tBodies[0];

        audits.forEach(function (audit) {
            var status = severityLabel(audit);
            var row = body.insertRow();
            var issues = Array.isArray(audit.issues) ? audit.issues : [];
            row.innerHTML = '<td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>';
            row.cells[0].textContent = status;
            row.cells[0].dataset.sortValue = status;
            row.cells[1].textContent = audit.engine || '';
            row.cells[2].textContent = audit.package_name || '';
            var link = document.createElement('a');
            link.href = 'file-info.php?id=' + encodeURIComponent(audit.file_id);
            link.textContent = audit.original_name || ('file #' + audit.file_id);
            row.cells[3].appendChild(link);
            row.cells[4].textContent = String((audit.stored_counts || {}).names || 0) + ' / ' + String((audit.fresh_counts || {}).names || 0);
            row.cells[5].textContent = String((audit.stored_counts || {}).imports || 0) + ' / ' + String((audit.fresh_counts || {}).imports || 0);
            row.cells[6].textContent = String((audit.stored_counts || {}).exports || 0) + ' / ' + String((audit.fresh_counts || {}).exports || 0);
            row.cells[7].textContent = Number(audit.error_count || 0) + ' errors / ' + Number(audit.warning_count || 0) + ' warnings';
            if (issues.length) {
                row.title = issues.slice(0, 20).map(issueText).join('\n');
            }
        });

        failures.forEach(function (failure) {
            var row = body.insertRow();
            row.innerHTML = '<td>failed</td><td></td><td></td><td></td><td colspan="4"></td>';
            row.cells[3].textContent = failure.file || '';
            row.cells[4].textContent = failure.error || 'Audit failed.';
        });

        var region = document.createElement('div');
        region.className = 'ui-table-region';
        region.appendChild(table);
        results.appendChild(region);
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        var gameId = gameSelect.value;
        var csrf = form.querySelector('[name="csrf"]').value;
        var gameLabel = gameSelect.options[gameSelect.selectedIndex].text;
        var overlay = CatalogLongJob.create({
            title: 'Auditing legacy package data',
            message: 'Loading files for ' + gameLabel + '…',
            count: 'Preparing read-only audit…'
        });
        form.querySelectorAll('button,select').forEach(function (control) { control.disabled = true; });

        var listData = new FormData();
        listData.set('csrf', csrf);
        listData.set('operation', 'list_files');
        listData.set('game_id', gameId);

        post(listData).then(async function (payload) {
            var files = Array.isArray(payload.files) ? payload.files : [];
            var audits = [];
            var failures = [];
            var totalErrors = 0;
            var totalWarnings = 0;

            for (var index = 0; index < files.length; index++) {
                var file = files[index];
                var token = CatalogLongJob.makeToken();
                var data = new FormData();
                data.set('csrf', csrf);
                data.set('operation', 'audit_file');
                data.set('file_id', String(file.id));
                data.set('progress_token', token);

                var stopPolling = CatalogLongJob.poll(endpoint, token, function (state) {
                    var local = Math.max(0, Math.min(100, Number(state.percent || 0)));
                    var overall = ((index + (local / 100)) / Math.max(1, files.length)) * 100;
                    overlay.update({
                        percent: overall,
                        message: 'Auditing ' + (index + 1) + '/' + files.length + ': '
                            + (file.original_name || file.package_name || ('file #' + file.id))
                            + ' — ' + (state.message || 'working'),
                        count: index + ' of ' + files.length + ' files complete'
                    });
                }, 450);

                try {
                    var resultPayload = await post(data);
                    stopPolling();
                    var audit = resultPayload.result || {};
                    audits.push(audit);
                    totalErrors += Number(audit.error_count || 0);
                    totalWarnings += Number(audit.warning_count || 0);
                    if (Number(audit.error_count || 0) > 0 || Number(audit.warning_count || 0) > 0) {
                        overlay.addLog((audit.original_name || file.original_name || 'package') + ': '
                            + Number(audit.error_count || 0) + ' errors, '
                            + Number(audit.warning_count || 0) + ' warnings', Number(audit.error_count || 0) > 0);
                    }
                } catch (error) {
                    stopPolling();
                    failures.push({file: file.original_name || file.package_name || ('file #' + file.id), error: error.message || 'Audit failed.'});
                    overlay.addLog(failures[failures.length - 1].file + ': ' + failures[failures.length - 1].error, true);
                }

                overlay.update({
                    percent: ((index + 1) / Math.max(1, files.length)) * 100,
                    message: 'Completed ' + (index + 1) + '/' + files.length + ': ' + (file.original_name || file.package_name || ('file #' + file.id)),
                    count: (index + 1) + ' of ' + files.length + ' files complete'
                });
            }

            renderRows(audits, failures);
            summary.textContent = 'Audited ' + audits.length + ' files. ' + totalErrors + ' data errors, '
                + totalWarnings + ' warnings, ' + failures.length + ' reader/request failures.';
            overlay.complete('Legacy data audit complete: ' + totalErrors + ' errors, ' + totalWarnings + ' warnings, ' + failures.length + ' failures.', 'Complete');
            overlay.addAction('View results', null, function () {
                overlay.destroy();
                document.getElementById('legacy-audit-results-section').scrollIntoView({block: 'start'});
            });
        }).catch(function (error) {
            overlay.fail(error.message || 'Legacy data audit failed.');
            overlay.addAction('Close', null, function () { overlay.destroy(); });
        }).finally(function () {
            form.querySelectorAll('button,select').forEach(function (control) { control.disabled = false; });
        });
    });
})();
