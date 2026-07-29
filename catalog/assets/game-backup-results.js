(function () {
    'use strict';

    function clean(value) {
        return String(value == null ? '' : value)
            .replace(/[\t\r\n]+/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function fallbackCopy(text) {
        var textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', 'readonly');
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        textarea.remove();
    }

    function copyText(text, button) {
        function complete() {
            var original = button.textContent;
            button.textContent = 'Copied';
            window.setTimeout(function () { button.textContent = original; }, 1500);
        }

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(complete).catch(function () {
                fallbackCopy(text);
                complete();
            });
            return;
        }
        fallbackCopy(text);
        complete();
    }

    function requestError(body, status) {
        if (body && body.error && body.error.message) return String(body.error.message);
        if (body && typeof body.error === 'string') return body.error;
        return 'Could not load the stored job result (HTTP ' + status + ').';
    }

    async function loadJob(jobId) {
        var url = 'api/v1/job-status.php?' + new URLSearchParams({job_id: String(jobId)}).toString();
        var response = await fetch(url, {cache: 'no-store', credentials: 'same-origin'});
        var body;
        try {
            body = await response.json();
        } catch (error) {
            throw new Error('The stored job result returned invalid JSON (HTTP ' + response.status + ').');
        }
        if (!response.ok) throw new Error(requestError(body, response.status));
        var jobs = body && body.data && Array.isArray(body.data.jobs) ? body.data.jobs : [];
        if (!jobs.length) throw new Error('The stored backup job could not be found.');
        return jobs[0];
    }

    function addStyles() {
        if (document.getElementById('game-backup-result-styles')) return;
        var style = document.createElement('style');
        style.id = 'game-backup-result-styles';
        style.textContent = [
            '.game-backup-result-row td{padding-top:0;border-top:0}',
            '.game-backup-result-panel{border-left:3px solid var(--line2);background:rgba(255,255,255,.018);padding:10px 12px 12px}',
            '.game-backup-result-panel.is-failed{border-left-color:var(--red);background:rgba(255,107,122,.035)}',
            '.game-backup-result-panel details>summary{cursor:pointer;font-weight:700}',
            '.game-backup-result-summary{margin:0 0 10px}',
            '.game-backup-result-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:8px 0}',
            '.game-backup-error-list{margin:8px 0 0;padding-left:22px}',
            '.game-backup-error-list li{margin:0 0 10px}',
            '.game-backup-error-file{display:block;font-weight:700;overflow-wrap:anywhere}',
            '.game-backup-error-path,.game-backup-error-message{display:block;overflow-wrap:anywhere}',
            '.game-backup-error-message{color:#fecdd3}',
            '.game-backup-result-loading{color:var(--muted)}'
        ].join('\n');
        document.head.appendChild(style);
    }

    function errorRows(result) {
        return result && Array.isArray(result.errors) ? result.errors : [];
    }

    function copyLines(errors) {
        return errors.map(function (entry) {
            var file = clean(entry && entry.file ? entry.file : '(unknown file)');
            var path = clean(entry && entry.exported_relative_path ? entry.exported_relative_path : '');
            var message = clean(entry && entry.error ? entry.error : 'No error message was recorded.');
            return file + (path && path !== file ? ' [' + path + ']' : '') + '\t' + message;
        }).join('\r\n');
    }

    function renderResult(mainRow, job) {
        var result = job && job.result && typeof job.result === 'object' ? job.result : {};
        var failed = Number(result.failed || 0);
        var imported = Number(result.imported || 0);
        var duplicates = Number(result.duplicates || 0);
        var aliases = Number(result.aliases || 0);
        var errors = errorRows(result);

        var existing = mainRow.nextElementSibling;
        if (existing && existing.classList.contains('game-backup-result-row')) existing.remove();

        var row = document.createElement('tr');
        row.className = 'game-backup-result-row';
        var cell = document.createElement('td');
        cell.colSpan = Math.max(1, mainRow.cells.length);
        var panel = document.createElement('div');
        panel.className = 'game-backup-result-panel' + (failed > 0 ? ' is-failed' : '');

        var summary = document.createElement('div');
        summary.className = 'game-backup-result-summary';
        summary.textContent = imported + ' imported, ' + duplicates + ' duplicate, ' + aliases + ' alias, ' + failed + ' failed.';
        panel.appendChild(summary);

        if (failed > 0) {
            var details = document.createElement('details');
            details.open = true;
            var heading = document.createElement('summary');
            heading.textContent = failed + ' failed file' + (failed === 1 ? '' : 's') + ' — filename and exact error';
            details.appendChild(heading);

            if (errors.length) {
                var actions = document.createElement('div');
                actions.className = 'game-backup-result-actions';
                var copyButton = document.createElement('button');
                copyButton.type = 'button';
                copyButton.textContent = 'Copy failed filenames + errors';
                copyButton.addEventListener('click', function () { copyText(copyLines(errors), copyButton); });
                actions.appendChild(copyButton);
                if (result.errors_truncated) {
                    var truncated = document.createElement('span');
                    truncated.className = 'muted small';
                    truncated.textContent = 'Only the first ' + errors.length + ' failures were retained in the job result.';
                    actions.appendChild(truncated);
                }
                details.appendChild(actions);

                var list = document.createElement('ol');
                list.className = 'game-backup-error-list';
                errors.forEach(function (entry) {
                    var item = document.createElement('li');
                    var file = document.createElement('span');
                    file.className = 'game-backup-error-file mono';
                    file.textContent = clean(entry && entry.file ? entry.file : '(unknown file)');
                    item.appendChild(file);

                    var path = clean(entry && entry.exported_relative_path ? entry.exported_relative_path : '');
                    if (path) {
                        var pathLine = document.createElement('span');
                        pathLine.className = 'game-backup-error-path mono small muted';
                        pathLine.textContent = 'Backup path: ' + path;
                        item.appendChild(pathLine);
                    }

                    var message = document.createElement('span');
                    message.className = 'game-backup-error-message';
                    message.textContent = clean(entry && entry.error ? entry.error : 'No error message was recorded.');
                    item.appendChild(message);
                    list.appendChild(item);
                });
                details.appendChild(list);
            } else {
                var missing = document.createElement('p');
                missing.className = 'game-backup-error-message';
                missing.textContent = 'The job reports failed files, but no per-file error records were stored.';
                details.appendChild(missing);
            }
            panel.appendChild(details);
        }

        cell.appendChild(panel);
        row.appendChild(cell);
        mainRow.after(row);
    }

    function renderLoadError(mainRow, message) {
        var row = document.createElement('tr');
        row.className = 'game-backup-result-row';
        var cell = document.createElement('td');
        cell.colSpan = Math.max(1, mainRow.cells.length);
        var panel = document.createElement('div');
        panel.className = 'game-backup-result-panel is-failed';
        panel.textContent = message;
        cell.appendChild(panel);
        row.appendChild(cell);
        mainRow.after(row);
    }

    function init() {
        if (!/\/game-backups\.php$/i.test(window.location.pathname)) return;
        var heading = Array.from(document.querySelectorAll('.card h2')).find(function (node) {
            return clean(node.textContent) === 'Recent backup jobs';
        });
        var card = heading ? heading.closest('.card') : null;
        var table = card ? card.querySelector('table') : null;
        if (!table || table.dataset.backupResultsBound === '1') return;
        table.dataset.backupResultsBound = '1';
        addStyles();

        Array.from(table.rows).slice(1).forEach(function (row) {
            if (!row.cells || row.cells.length < 4 || clean(row.cells[1].textContent).toLowerCase() !== 'import') return;
            var link = row.cells[0].querySelector('a');
            var match = link ? clean(link.textContent).match(/^#(\d+)$/) : null;
            if (!match) return;
            var jobId = Number(match[1]);
            var loading = document.createElement('span');
            loading.className = 'game-backup-result-loading small';
            loading.textContent = ' Loading stored file results…';
            row.cells[3].appendChild(loading);
            loadJob(jobId).then(function (job) {
                loading.remove();
                renderResult(row, job);
            }).catch(function (error) {
                loading.remove();
                renderLoadError(row, error && error.message ? error.message : 'Could not load the stored backup result.');
            });
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
}());
