(function () {
    'use strict';

    var DEPENDENCY_BATCH_SIZE = 100;
    var form = document.getElementById('full-sync-form');
    var fileList = document.getElementById('full-sync-files');
    if (!form || !fileList) return;

    var files;
    try {
        files = JSON.parse(fileList.textContent || '[]');
    } catch (error) {
        window.alert('The full sync file list could not be loaded. Refresh this page and try again.');
        return;
    }
    if (!Array.isArray(files) || files.length === 0) return;

    var phaseRanges = {
        sync: [0, 70],
        prepare: [70, 74],
        dependencies: [74, 97],
        finalize: [97, 100]
    };

    function makeToken() {
        var bytes = new Uint8Array(18);
        if (window.crypto && window.crypto.getRandomValues) {
            window.crypto.getRandomValues(bytes);
            return Array.from(bytes).map(function (value) {
                return value.toString(16).padStart(2, '0');
            }).join('');
        }
        return Date.now().toString(36) + Math.random().toString(36).slice(2);
    }

    function shortServerText(text) {
        return text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 260);
    }

    function parseJson(response) {
        return response.text().then(function (text) {
            var payload;
            try {
                payload = JSON.parse(text);
            } catch (error) {
                var detail = shortServerText(text);
                throw new Error(
                    'Server returned a non-JSON maintenance response (HTTP ' + response.status + ')'
                    + (detail ? ': ' + detail : '.')
                );
            }
            if (!response.ok && payload && !payload.error) {
                payload.error = 'Maintenance request failed with HTTP ' + response.status + '.';
            }
            return payload;
        });
    }

    function createOverlay() {
        var overlay = document.createElement('div');
        overlay.className = 'full-sync-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-live', 'assertive');
        overlay.innerHTML = '<div class="full-sync-dialog"><h2>Full game sync</h2>'
            + '<p class="full-sync-message">Preparing storage check…</p>'
            + '<div class="full-sync-progress"><span></span></div>'
            + '<div class="full-sync-count">Waiting for server…</div>'
            + '<div class="full-sync-loading"><span class="full-sync-spinner"></span>'
            + '<span>Loading updated sync page…</span></div></div>';
        document.body.appendChild(overlay);
        return overlay;
    }

    function phaseRange(phase) {
        return phaseRanges[phase] || [0, 100];
    }

    function phaseLabel(phase) {
        if (phase === 'sync') return 'Checking / rebuilding';
        if (phase === 'prepare') return 'Preparing dependency providers';
        if (phase === 'dependencies') return 'Refreshing dependencies';
        return 'Finalizing dependency summaries and game counters';
    }

    function setOverall(overlay, percent, message, countText) {
        var bounded = Math.max(0, Math.min(100, Number(percent || 0)));
        overlay.querySelector('.full-sync-progress > span').style.width = bounded + '%';
        overlay.querySelector('.full-sync-message').textContent = message;
        overlay.querySelector('.full-sync-count').textContent = countText;
    }

    function setPackageState(overlay, phase, completedBefore, phaseTotal, state, fileName) {
        var range = phaseRange(phase);
        var localPercent = Math.max(0, Math.min(100, Number(state.percent || 0)));
        var overall = range[0]
            + (((completedBefore + (localPercent / 100)) / Math.max(1, phaseTotal)) * (range[1] - range[0]));
        var message = state.message || 'Working…';
        setOverall(
            overlay,
            overall,
            phaseLabel(phase) + ' package ' + (completedBefore + 1) + '/' + phaseTotal
                + ' (' + fileName + '): ' + message,
            completedBefore + ' of ' + phaseTotal + ' verified packages processed ('
                + Math.round(overall) + '% overall)'
        );
    }

    function completePackageStep(overlay, phase, completed, phaseTotal, fileName, message) {
        var range = phaseRange(phase);
        var overall = range[0] + ((completed / Math.max(1, phaseTotal)) * (range[1] - range[0]));
        setOverall(
            overlay,
            overall,
            'Processed package ' + completed + '/' + phaseTotal + ' (' + fileName + '): ' + message,
            completed + ' of ' + phaseTotal + ' verified packages processed (' + Math.round(overall) + '% overall)'
        );
    }

    function setDependencyBatchState(overlay, completedBefore, phaseTotal, batchSize, state) {
        var range = phaseRange('dependencies');
        var localDone = Math.max(0, Math.min(batchSize, Number(state.done || 0)));
        var completed = Math.min(phaseTotal, completedBefore + localDone);
        var overall = range[0] + ((completed / Math.max(1, phaseTotal)) * (range[1] - range[0]));
        setOverall(
            overlay,
            overall,
            'Refreshing dependency batch: ' + (state.message || 'Working…'),
            completed + ' of ' + phaseTotal + ' packages dependency-refreshed ('
                + Math.round(overall) + '% overall)'
        );
    }

    function completeDependencyBatch(overlay, completed, phaseTotal, message) {
        var range = phaseRange('dependencies');
        var overall = range[0] + ((completed / Math.max(1, phaseTotal)) * (range[1] - range[0]));
        setOverall(
            overlay,
            overall,
            message,
            completed + ' of ' + phaseTotal + ' packages dependency-refreshed ('
                + Math.round(overall) + '% overall)'
        );
    }

    function setPhaseState(overlay, phase, state) {
        var range = phaseRange(phase);
        var localPercent = Math.max(0, Math.min(100, Number(state.percent || 0)));
        var overall = range[0] + ((localPercent / 100) * (range[1] - range[0]));
        setOverall(
            overlay,
            overall,
            phaseLabel(phase) + ': ' + (state.message || 'Working…'),
            Math.round(overall) + '% overall'
        );
    }

    function completePhase(overlay, phase, message) {
        var range = phaseRange(phase);
        setOverall(overlay, range[1], message, Math.round(range[1]) + '% overall');
    }

    function pollProgress(token, onState) {
        var active = true;
        var timer = null;
        function tick() {
            if (!active) return;
            fetch('file-maintenance.php?progress=' + encodeURIComponent(token), {
                credentials: 'same-origin',
                cache: 'no-store'
            }).then(parseJson).then(function (state) {
                if (active) onState(state);
            }).catch(function () {
                /* The active POST remains authoritative. Keep its last stage visible. */
            }).finally(function () {
                if (active) timer = window.setTimeout(tick, 450);
            });
        }
        tick();
        return function () {
            active = false;
            if (timer !== null) window.clearTimeout(timer);
        };
    }

    function postIdentity(data, file) {
        data.set('package_name', file.package_name || '');
        data.set('md5', file.md5 || '');
        data.set('package_guid', file.package_guid || '');
    }

    function isStaleFileError(message) {
        return /no longer exists in the catalog|no longer present in the catalog|Refresh Full Sync/i.test(message || '');
    }

    function postMaintenance(data, stopPolling) {
        return fetch(form.action, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
            body: data
        }).then(parseJson).then(function (result) {
            stopPolling();
            if (!result.ok) {
                throw new Error(result.error || 'Maintenance operation failed.');
            }
            return result;
        }).catch(function (error) {
            stopPolling();
            throw error;
        });
    }

    function runPackageRequest(overlay, operation, file, phase, completedBefore, phaseTotal) {
        var token = makeToken();
        var data = new FormData(form);
        data.set('operation', operation);
        data.set('file_id', String(file.id));
        data.set('progress_token', token);
        postIdentity(data, file);
        var stopPolling = pollProgress(token, function (state) {
            setPackageState(overlay, phase, completedBefore, phaseTotal, state, file.original_name || 'package');
        });
        return postMaintenance(data, stopPolling);
    }

    function runDependencyBatchRequest(overlay, batch, completedBefore, phaseTotal) {
        var token = makeToken();
        var data = new FormData(form);
        data.set('operation', 'sync_refresh_dependencies_batch');
        data.set('file_ids_json', JSON.stringify(batch.map(function (file) { return Number(file.id); })));
        data.set('progress_token', token);
        var stopPolling = pollProgress(token, function (state) {
            setDependencyBatchState(overlay, completedBefore, phaseTotal, batch.length, state);
        });
        return postMaintenance(data, stopPolling);
    }

    function runGamePhaseRequest(overlay, operation, phase) {
        var token = makeToken();
        var data = new FormData(form);
        data.set('operation', operation);
        data.set('progress_token', token);
        var stopPolling = pollProgress(token, function (state) {
            setPhaseState(overlay, phase, state);
        });
        return postMaintenance(data, stopPolling);
    }

    function showFailures(overlay, failures, returnUrl) {
        if (failures.length === 0) return false;
        overlay.querySelector('.full-sync-message').textContent = 'Full sync finished with '
            + failures.length + ' issue(s). Other catalog records continued.';
        var details = document.createElement('div');
        details.className = 'full-sync-failures';
        details.textContent = failures.join('\n');
        overlay.querySelector('.full-sync-dialog').appendChild(details);
        var actions = document.createElement('div');
        actions.className = 'full-sync-result-actions';
        var back = document.createElement('a');
        back.className = 'button';
        back.href = returnUrl;
        back.textContent = 'Return to full sync';
        actions.appendChild(back);
        overlay.querySelector('.full-sync-dialog').appendChild(actions);
        return true;
    }

    function appendBatchFailures(failures, result) {
        var batchFailures = Array.isArray(result.failures) ? result.failures : [];
        batchFailures.forEach(function (failure) {
            var name = failure.original_name || ('file #' + Number(failure.file_id || 0));
            failures.push('Dependency refresh failed — ' + name + ': ' + (failure.error || 'Unknown error'));
        });
    }

    async function processDependencyBatch(overlay, batch, completedBefore, phaseTotal, failures) {
        try {
            var result = await runDependencyBatchRequest(overlay, batch, completedBefore, phaseTotal);
            appendBatchFailures(failures, result);
            completeDependencyBatch(
                overlay,
                completedBefore + batch.length,
                phaseTotal,
                result.message || ('Dependency-refreshed batch of ' + batch.length + ' packages.')
            );
            return;
        } catch (error) {
            if (batch.length === 1) {
                failures.push(
                    'Dependency refresh failed — ' + (batch[0].original_name || ('file #' + batch[0].id))
                    + ': ' + (error.message || 'Unknown error')
                );
                completeDependencyBatch(
                    overlay,
                    completedBefore + 1,
                    phaseTotal,
                    'Skipped one dependency package after a batch request error; continuing.'
                );
                return;
            }
        }

        /* A request-level failure may be a timeout after some idempotent work completed.
         * Split the failed range and retry smaller bounded requests rather than dropping the whole batch. */
        var splitAt = Math.ceil(batch.length / 2);
        var first = batch.slice(0, splitAt);
        var second = batch.slice(splitAt);
        await processDependencyBatch(overlay, first, completedBefore, phaseTotal, failures);
        if (second.length > 0) {
            await processDependencyBatch(
                overlay,
                second,
                completedBefore + first.length,
                phaseTotal,
                failures
            );
        }
    }

    async function runFullSync(overlay) {
        var failures = [];
        var refreshFiles = [];
        var reimported = 0;
        var removed = 0;
        var total = files.length;

        for (var index = 0; index < total; index++) {
            var file = files[index];
            try {
                var result = await runPackageRequest(overlay, 'sync_reimport', file, 'sync', index, total);
                if (result.status === 'removed_missing') {
                    removed++;
                    completePackageStep(
                        overlay,
                        'sync',
                        index + 1,
                        total,
                        file.original_name,
                        result.message || 'Stored package missing; stale catalog record removed.'
                    );
                    continue;
                }

                reimported++;
                refreshFiles.push({
                    id: result.file_id,
                    original_name: result.original_name || file.original_name,
                    package_name: file.package_name || '',
                    md5: file.md5 || '',
                    package_guid: file.package_guid || ''
                });
                completePackageStep(
                    overlay,
                    'sync',
                    index + 1,
                    total,
                    file.original_name,
                    result.message || 'Scanner re-import complete.'
                );
            } catch (error) {
                var message = error.message || 'Unknown error';
                failures.push('Re-import failed — ' + file.original_name + ': ' + message);
                if (!isStaleFileError(message)) {
                    /* A genuine scanner failure restores the old file record, so it still belongs in the final dependency pass. */
                    refreshFiles.push(file);
                }
                completePackageStep(
                    overlay,
                    'sync',
                    index + 1,
                    total,
                    file.original_name,
                    'Skipped after error; continuing with the next package.'
                );
            }
        }

        try {
            var prepareResult = await runGamePhaseRequest(overlay, 'sync_prepare_dependencies', 'prepare');
            var providers = prepareResult.providers || {};
            completePhase(
                overlay,
                'prepare',
                'Package providers rebuilt: ' + Number(providers.primary || 0)
                    + ' primary, ' + Number(providers.aliases || 0) + ' aliases.'
            );
        } catch (error) {
            failures.push('Provider projection preparation failed: ' + (error.message || 'Unknown error'));
            completePhase(
                overlay,
                'prepare',
                'Provider preparation reported an issue; dependency refresh will continue using authoritative fallbacks.'
            );
        }

        for (var batchStart = 0; batchStart < refreshFiles.length; batchStart += DEPENDENCY_BATCH_SIZE) {
            var batch = refreshFiles.slice(batchStart, batchStart + DEPENDENCY_BATCH_SIZE);
            await processDependencyBatch(overlay, batch, batchStart, refreshFiles.length, failures);
        }

        if (refreshFiles.length === 0) {
            completePhase(
                overlay,
                'dependencies',
                'No stored packages remained after validation; package dependency refresh was not required.'
            );
        }

        var finalStats = null;
        try {
            var finalResult = await runGamePhaseRequest(overlay, 'sync_finalize_game', 'finalize');
            finalStats = finalResult.stats || null;
            completePhase(
                overlay,
                'finalize',
                finalStats
                    ? 'Full Sync projections finalized: '
                        + Number(finalStats.missing_dependency_count || 0)
                        + ' missing dependencies across '
                        + Number(finalStats.missing_package_count || 0) + ' package names.'
                    : (finalResult.message || 'Full Sync projections finalized.')
            );
        } catch (error) {
            failures.push('Final projection refresh failed: ' + (error.message || 'Unknown error'));
            completePhase(overlay, 'finalize', 'Final projection refresh reported an issue.');
        }

        setOverall(
            overlay,
            100,
            failures.length === 0 ? 'Full sync complete.' : 'Full sync completed with issues.',
            reimported + ' re-imported, ' + removed + ' missing storage record(s) removed, from '
                + total + ' verified catalog record(s).'
        );

        var returnUrl = 'full-sync.php?game_id='
            + encodeURIComponent(form.querySelector('[name="game_id"]').value)
            + '&synced=' + encodeURIComponent(reimported)
            + '&removed=' + encodeURIComponent(removed)
            + '&total=' + encodeURIComponent(total)
            + '&failed=' + encodeURIComponent(failures.length);
        if (showFailures(overlay, failures, returnUrl)) return;

        overlay.querySelector('.full-sync-loading').classList.add('is-visible');
        window.setTimeout(function () { window.location.assign(returnUrl); }, 120);
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        var gameSelect = document.getElementById('full-sync-game');
        var gameName = gameSelect ? gameSelect.options[gameSelect.selectedIndex].text : 'this game';
        if (!window.confirm(
            'Run a full sync for ' + gameName
            + '? Every verified package will be checked against storage; missing stored files will be removed from the catalog.'
        )) return;

        var overlay = createOverlay();
        form.querySelectorAll('button').forEach(function (button) { button.disabled = true; });
        runFullSync(overlay).catch(function (error) {
            overlay.remove();
            form.querySelectorAll('button').forEach(function (button) { button.disabled = false; });
            window.alert(error.message || 'Full sync failed.');
        });
    });
})();
