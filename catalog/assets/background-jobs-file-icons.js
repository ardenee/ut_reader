(function () {
    'use strict';

    const body = document.getElementById('jobs-file-body');
    const app = document.getElementById('background-jobs-app');
    if (!body || !app) return;

    const spriteUrl = 'assets/file-icons.svg?v=20260824-2';
    const bulkUrl = String(app.dataset.bulkUrl || 'api/v1/job-bulk.php');
    const queue = String(app.dataset.queue || 'catalog');
    const csrf = String(app.dataset.csrf || '');
    const selectVisible = document.getElementById('jobs-select-visible');
    const supported = new Set([
        'default',
        'u', 'ut2', 'ut3', 'unr', 'un2', 'umap',
        'utx', 'usx', 'ukx', 'uax', 'umx', 'upx', 'ugx',
        'uasset', 'md5', 'bak',
        'umod', 'ut2mod', 'ut4mod',
        'zip', 'rar', '7z', 'pak', 'upk', 'uz', 'uz2', 'uz3'
    ]);
    let stickyNotice = {text: '', until: 0};

    function detectedArchiveKey(identity) {
        if (!identity) return '';
        const text = String(identity.textContent || '').toLowerCase();
        if (text.includes('rar archive')) return 'rar';
        if (text.includes('7-zip archive')) return '7z';
        if (text.includes('zip archive')) return 'zip';
        return '';
    }

    function extensionKey(fileName) {
        const name = String(fileName || '').trim().toLowerCase();
        const dot = name.lastIndexOf('.');
        if (dot < 0 || dot === name.length - 1) return 'default';
        const extension = name.slice(dot + 1);
        return supported.has(extension) ? extension : 'default';
    }

    function iconKey(row) {
        const identity = row.querySelector('.jobs-file-identity');
        const detected = detectedArchiveKey(identity);
        if (detected) return detected;
        const name = identity ? identity.querySelector('strong') : null;
        return extensionKey(name ? name.textContent : '');
    }

    function hierarchyMarker(depth, rootGroup) {
        const alternate = Math.abs(Number(rootGroup || 0)) % 2;
        if (depth <= 1) {
            return alternate === 0
                ? 'rgba(148,163,184,0.90)'
                : 'rgba(96,165,250,0.95)';
        }
        return alternate === 0
            ? 'rgba(34,211,238,0.95)'
            : 'rgba(167,139,250,0.95)';
    }

    function hierarchyIndent(depth) {
        if (depth < 1) return 0;
        return 42 + (Math.min(depth - 1, 5) * 64);
    }

    function applyHierarchy(row) {
        if (!(row instanceof HTMLElement) || !row.classList.contains('jobs-file-row')) return;

        const tree = row.querySelector('.jobs-file-tree');
        const fileCell = row.querySelector('.jobs-file-name-cell');
        if (!tree || !fileCell) return;

        const depth = Math.max(0, parseInt(String(row.dataset.depth || '0'), 10) || 0);
        const rootGroup = Number(row.dataset.rootGroup || 0);

        fileCell.style.setProperty('border-left', '0', 'important');
        tree.style.setProperty('padding-left', depth > 0 ? '12px' : '0', 'important');
        tree.style.setProperty('margin-left', hierarchyIndent(depth) + 'px', 'important');

        if (depth < 1) {
            tree.style.removeProperty('border-left');
            tree.style.removeProperty('box-shadow');
            return;
        }

        const marker = hierarchyMarker(depth, rootGroup);
        tree.style.setProperty('border-left', (depth > 1 ? '6px' : '4px') + ' solid ' + marker, 'important');
        tree.style.setProperty('box-shadow', depth > 1
            ? '-1px 0 0 rgba(15,23,42,0.65)'
            : 'none', 'important');
    }

    function addIcon(row) {
        if (!(row instanceof HTMLElement) || !row.classList.contains('jobs-file-row')) return;

        applyHierarchy(row);
        if (row.querySelector('.jobs-file-type-icon')) return;

        const tree = row.querySelector('.jobs-file-tree');
        const identity = row.querySelector('.jobs-file-identity');
        if (!tree || !identity) return;

        const key = iconKey(row);
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.classList.add('jobs-file-type-icon');
        svg.setAttribute('viewBox', '0 0 64 64');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('focusable', 'false');

        const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
        use.setAttribute('href', spriteUrl + '#file-icon-' + key);
        svg.appendChild(use);
        tree.insertBefore(svg, identity);
    }

    function showNotice(text, milliseconds) {
        const message = String(text || '').trim();
        if (!message) return;
        stickyNotice = {
            text: message,
            until: Date.now() + Math.max(3000, Number(milliseconds || 12000))
        };
        restoreStickyNotice();
    }

    function restoreStickyNotice() {
        if (!stickyNotice.text || Date.now() >= stickyNotice.until) return;
        const notice = document.getElementById('jobs-file-notice');
        if (notice && notice.textContent !== stickyNotice.text) {
            notice.textContent = stickyNotice.text;
        }
    }

    function setControlDisabled(button, disabled) {
        if (!(button instanceof HTMLButtonElement)) return;
        button.disabled = Boolean(disabled);
        if (disabled) {
            button.setAttribute('aria-disabled', 'true');
        } else {
            button.removeAttribute('aria-disabled');
        }
    }

    function selectedRootRows() {
        return Array.from(body.querySelectorAll('.jobs-file-row[data-depth="0"]')).filter(function (row) {
            const checkbox = row.querySelector('input.jobs-file-row-select');
            return checkbox instanceof HTMLInputElement && checkbox.checked;
        });
    }

    function syncBulkControlState() {
        const selectedRows = selectedRootRows();
        const retry = document.getElementById('jobs-retry-selected');
        const stop = document.getElementById('jobs-stop-selected');
        const remove = document.getElementById('jobs-delete-selected');
        const workingSelected = selectedRows.some(function (row) {
            const status = String(row.querySelector('.jobs-file-status')?.textContent || '').trim().toLowerCase();
            return status === 'working';
        });

        setControlDisabled(retry, selectedRows.length === 0);
        setControlDisabled(remove, selectedRows.length === 0);
        setControlDisabled(stop, !workingSelected);
    }

    function isRetryableDependencyParent(row) {
        if (!(row instanceof HTMLElement) || !row.classList.contains('jobs-file-row')) return false;
        const depth = Math.max(0, parseInt(String(row.dataset.depth || '0'), 10) || 0);
        if (depth !== 0) return false;

        const jobType = String(row.querySelector('.jobs-file-type')?.textContent || '').trim();
        if (jobType !== 'catalog.rebuild_affected_dependencies') return false;

        const status = String(row.querySelector('.jobs-file-status')?.textContent || '').trim().toLowerCase();
        return status === 'issue' || status === 'stopped';
    }

    async function retryDependencyParent(row, button) {
        const jobId = Math.max(0, parseInt(String(row.dataset.jobId || '0'), 10) || 0);
        if (!jobId) return;

        button.disabled = true;
        button.textContent = 'Retrying…';
        try {
            const response = await fetch(bulkUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrf
                },
                body: JSON.stringify({
                    action: 'restart',
                    scope: 'selected',
                    queue: queue,
                    status: '',
                    search: '',
                    job_ids: [jobId]
                })
            });
            let payload = {};
            try {
                payload = await response.json();
            } catch (_error) {
                payload = {};
            }
            if (!response.ok) {
                const message = payload && payload.error && payload.error.message
                    ? String(payload.error.message)
                    : 'Retry failed with HTTP ' + response.status + '.';
                throw new Error(message);
            }

            const data = payload && payload.data ? payload.data : {};
            const queued = Math.max(0, Number(data.affected || 0));
            const childCount = Math.max(0, Number(data.affected_dependency_recovery_jobs || 0));
            const blocked = Math.max(0, Number(data.retry_blocked || 0));
            const skipped = Math.max(0, Number(data.skipped || 0));

            let message = 'Parent job #' + jobId + ': ' + queued + ' child recovery job(s) queued';
            if (childCount > 0 && childCount !== queued) {
                message += ' of ' + childCount + ' needing recovery';
            }
            if (blocked > 0) message += ', ' + blocked + ' blocked as non-retryable';
            if (skipped > blocked) message += ', ' + (skipped - blocked) + ' skipped';
            message += '.';
            const workerError = String(data.worker_error || '').trim();
            if (workerError) message += ' Worker start warning: ' + workerError;

            showNotice(message, 15000);
            button.textContent = queued > 0 ? 'Queued ' + queued : 'Retry';
            button.title = message;
            window.setTimeout(function () {
                const refresh = document.getElementById('jobs-refresh');
                if (refresh instanceof HTMLElement) refresh.click();
            }, 350);
        } catch (error) {
            const message = error && error.message ? error.message : 'Could not retry this parent job.';
            showNotice('Retry parent #' + jobId + ' failed: ' + message, 15000);
            button.disabled = false;
            button.textContent = 'Retry';
            button.title = message;
        }
    }

    function addParentRetry(row) {
        const existingChild = row.querySelector('.jobs-file-child-retry');
        if (existingChild) existingChild.remove();

        const existing = row.querySelector('.jobs-file-parent-retry');
        const retryable = isRetryableDependencyParent(row);
        if (!retryable) {
            if (existing) existing.remove();
            return;
        }
        if (existing) return;

        const control = row.querySelector('.jobs-file-control');
        if (!control) return;
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'ui-button ui-button--primary ui-button--sm jobs-file-parent-retry';
        button.textContent = 'Retry';
        button.title = 'Retry all failed or stopped dependency child jobs beneath this parent.';
        button.addEventListener('click', function () {
            retryDependencyParent(row, button);
        });
        control.appendChild(button);
    }

    function makeBulkRetryVisiblyPrimary() {
        const button = document.getElementById('jobs-retry-selected');
        if (!button) return;
        button.classList.remove('ui-button--secondary');
        button.classList.add('ui-button--primary');
        button.title = 'Retry the selected parent source job(s). Dependency parents automatically queue all failed/stopped child recovery work.';
    }

    function enhanceRow(row) {
        addIcon(row);
        addParentRetry(row);
    }

    function refreshRows(root) {
        if (root instanceof HTMLElement && root.classList.contains('jobs-file-row')) enhanceRow(root);
        (root || body).querySelectorAll('.jobs-file-row').forEach(enhanceRow);
        makeBulkRetryVisiblyPrimary();
        syncBulkControlState();
        restoreStickyNotice();
    }

    body.addEventListener('change', function (event) {
        const target = event.target;
        if (target instanceof HTMLInputElement && target.classList.contains('jobs-file-row-select')) {
            window.setTimeout(syncBulkControlState, 0);
        }
    });
    if (selectVisible instanceof HTMLInputElement) {
        selectVisible.addEventListener('change', function () {
            window.setTimeout(syncBulkControlState, 0);
        });
    }

    refreshRows(body);
    new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
            mutation.addedNodes.forEach(function (node) {
                if (node instanceof HTMLElement) refreshRows(node);
            });
        });
    }).observe(body, {childList: true, subtree: true});
}());
