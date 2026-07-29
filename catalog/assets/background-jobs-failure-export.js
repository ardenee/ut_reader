(function () {
    'use strict';

    const app = document.getElementById('background-jobs-app');
    const selectionBar = document.querySelector('.jobs-selectionbar');
    if (!app || !selectionBar || !window.fetch) return;

    const statusUrl = app.dataset.statusUrl || 'api/v1/job-status.php';
    let rows = [];

    function clean(value) {
        return String(value == null ? '' : value)
            .replace(/^RuntimeException:\s*/i, '')
            .replace(/[\t\r\n]+/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function target(job) {
        const payload = job && job.payload && typeof job.payload === 'object' ? job.payload : {};
        if (payload.source_relative_path) return clean(payload.source_relative_path);
        if (payload.original_name) return clean(payload.original_name);
        if (payload.file_id) return 'File #' + payload.file_id;
        if (payload.game_id) return 'Game #' + payload.game_id;
        return clean(job && job.concurrency_key ? job.concurrency_key : '');
    }

    function errorText(job) {
        if (job && job.last_error) return clean(job.last_error);
        if (job && job.cancel_reason) return clean(job.cancel_reason);
        if (job && job.result && job.result.message) return clean(job.result.message);
        return '';
    }

    function readJson(url) {
        return new Promise(function (resolve, reject) {
            const request = new XMLHttpRequest();
            request.open('GET', url, true);
            request.withCredentials = true;
            request.setRequestHeader('Cache-Control', 'no-cache');
            request.onreadystatechange = function () {
                if (request.readyState !== 4) return;
                let body;
                try {
                    body = JSON.parse(request.responseText || '');
                } catch (error) {
                    reject(new Error('The server returned invalid JSON (HTTP ' + request.status + ').'));
                    return;
                }
                if (request.status < 200 || request.status >= 300) {
                    reject(new Error(body && body.error && body.error.message
                        ? String(body.error.message)
                        : 'The request failed with HTTP ' + request.status + '.'));
                    return;
                }
                resolve(body);
            };
            request.onerror = function () {
                reject(new Error('The request could not reach the background-job API.'));
            };
            request.send();
        });
    }

    async function loadAll(progress) {
        const current = new URLSearchParams(window.location.search);
        const params = new URLSearchParams({
            queue: app.dataset.queue || current.get('queue') || 'catalog',
            page: '1',
            per_page: '1000'
        });
        const selectedStatus = clean(current.get('status'));
        const search = clean(current.get('search'));
        if (selectedStatus) params.set('status', selectedStatus);
        if (search) params.set('search', search);

        const jobs = [];
        let page = 1;
        let pages = 1;
        do {
            params.set('page', String(page));
            progress.textContent = 'Loading matching jobs: page ' + page + (pages > 1 ? ' of ' + pages : '') + '…';
            const body = await readJson(statusUrl + '?' + params.toString());
            const batch = body && body.data && Array.isArray(body.data.jobs) ? body.data.jobs : [];
            jobs.push.apply(jobs, batch);
            pages = Math.max(1, Number(body && body.meta ? body.meta.pages || 1 : 1));
            page++;
        } while (page <= pages);
        return jobs;
    }

    function makeRows(jobs, includeRepeated) {
        const seen = new Set();
        const output = [];
        jobs.forEach(function (job) {
            const name = target(job);
            const message = errorText(job);
            if (!name || !message) return;
            const key = name.toLocaleLowerCase();
            if (!includeRepeated && seen.has(key)) return;
            seen.add(key);
            output.push({name: name, error: message});
        });
        return output;
    }

    function namesOnly() {
        return rows.map(function (row) { return row.name; }).join('\n');
    }

    function fullList(header) {
        const lines = rows.map(function (row) { return row.name + '\t' + row.error; });
        if (header) lines.unshift('File / target\tError message');
        return lines.join('\n');
    }

    async function copy(text) {
        if (!text) throw new Error('Generate the list first.');
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
            return;
        }
        const helper = document.createElement('textarea');
        helper.value = text;
        helper.readOnly = true;
        helper.style.position = 'fixed';
        helper.style.opacity = '0';
        document.body.appendChild(helper);
        helper.select();
        const ok = document.execCommand('copy');
        helper.remove();
        if (!ok) throw new Error('The browser could not copy the list.');
    }

    function download(text) {
        if (!text) throw new Error('Generate the list first.');
        const stamp = new Date().toISOString().replace(/[-:]/g, '').replace(/\..+$/, '').replace('T', '-');
        const url = URL.createObjectURL(new Blob(['\ufeff' + text], {type: 'text/tab-separated-values;charset=utf-8'}));
        const link = document.createElement('a');
        link.href = url;
        link.download = 'background-job-failures-' + stamp + '.tsv';
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    }

    const style = document.createElement('style');
    style.textContent = '.jobs-failure-export{margin:0 0 16px;border:1px solid var(--line);border-radius:10px;background:rgba(255,255,255,.018)}'
        + '.jobs-failure-export summary{cursor:pointer;font-weight:700;padding:11px 13px}'
        + '.jobs-failure-export-body{padding:0 13px 13px}'
        + '.jobs-failure-export-controls{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:10px}'
        + '.jobs-failure-export textarea{width:100%;min-height:260px;resize:vertical;font-family:Consolas,ui-monospace,monospace;font-size:12px;line-height:1.4;white-space:pre}'
        + '.jobs-failure-export-status{margin:8px 0 0}';
    document.head.appendChild(style);

    const panel = document.createElement('details');
    panel.className = 'jobs-failure-export';
    panel.innerHTML = '<summary>Compact filename / error list</summary>'
        + '<div class="jobs-failure-export-body"><div class="jobs-failure-export-controls">'
        + '<button type="button" data-action="generate">Generate from current filters</button>'
        + '<label><input type="checkbox" data-option="repeat"> Include repeated attempts</label>'
        + '<button type="button" data-action="copy-names" disabled>Copy filenames</button>'
        + '<button type="button" data-action="copy-full" disabled>Copy filename + error</button>'
        + '<button type="button" data-action="download" disabled>Download TSV</button>'
        + '</div><textarea readonly spellcheck="false" placeholder="Generate the list to show one file and error per line."></textarea>'
        + '<p class="muted jobs-failure-export-status" aria-live="polite">Uses the current queue, status tab and search filter. Repeated attempts for the same file are hidden by default.</p></div>';
    selectionBar.insertAdjacentElement('afterend', panel);

    const generate = panel.querySelector('[data-action="generate"]');
    const repeated = panel.querySelector('[data-option="repeat"]');
    const copyNames = panel.querySelector('[data-action="copy-names"]');
    const copyFull = panel.querySelector('[data-action="copy-full"]');
    const downloadButton = panel.querySelector('[data-action="download"]');
    const output = panel.querySelector('textarea');
    const progress = panel.querySelector('.jobs-failure-export-status');

    function enableActions(enabled) {
        copyNames.disabled = !enabled;
        copyFull.disabled = !enabled;
        downloadButton.disabled = !enabled;
    }

    generate.addEventListener('click', async function () {
        generate.disabled = true;
        enableActions(false);
        output.value = '';
        try {
            const jobs = await loadAll(progress);
            rows = makeRows(jobs, repeated.checked);
            output.value = fullList(false);
            const failureCount = jobs.filter(function (job) { return target(job) && errorText(job); }).length;
            const hidden = Math.max(0, failureCount - rows.length);
            progress.textContent = rows.length + ' failure' + (rows.length === 1 ? '' : 's') + ' generated from '
                + jobs.length + ' matching job' + (jobs.length === 1 ? '' : 's')
                + (hidden ? '; ' + hidden + ' repeated attempt' + (hidden === 1 ? '' : 's') + ' hidden.' : '.');
            enableActions(rows.length > 0);
        } catch (error) {
            rows = [];
            progress.textContent = error && error.message ? error.message : 'Could not generate the failure list.';
        } finally {
            generate.disabled = false;
        }
    });

    repeated.addEventListener('change', function () {
        if (rows.length || output.value) progress.textContent = 'Generate the list again to apply the repeated-attempt option.';
    });

    copyNames.addEventListener('click', async function () {
        try {
            await copy(namesOnly());
            progress.textContent = rows.length + ' filename' + (rows.length === 1 ? '' : 's') + ' copied.';
        } catch (error) {
            progress.textContent = error.message || 'Could not copy filenames.';
        }
    });

    copyFull.addEventListener('click', async function () {
        try {
            await copy(fullList(false));
            progress.textContent = rows.length + ' filename/error row' + (rows.length === 1 ? '' : 's') + ' copied.';
        } catch (error) {
            progress.textContent = error.message || 'Could not copy the list.';
        }
    });

    downloadButton.addEventListener('click', function () {
        try {
            download(fullList(true));
            progress.textContent = rows.length + ' filename/error row' + (rows.length === 1 ? '' : 's') + ' downloaded as TSV.';
        } catch (error) {
            progress.textContent = error.message || 'Could not download the list.';
        }
    });
}());
