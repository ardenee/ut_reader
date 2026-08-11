<?php
/**
 * Cross-examine sibling games for verified packages that can satisfy exact missing dependencies.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Unverified\PdoGameDependencyCrossExamineQuery;

function cross_exam_int(string $key, int $default = 0): int
{
    $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);
    return $value === false || $value === null ? $default : (int)$value;
}

function cross_exam_text(string $key): string
{
    $value = filter_input(INPUT_GET, $key, FILTER_UNSAFE_RAW);
    return is_string($value) ? trim($value) : '';
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Dependency Cross-Examine')) {
        exit;
    }

    $query = new PdoGameDependencyCrossExamineQuery($db, $config);
    $games = $query->games();
    $targetGameId = max(0, cross_exam_int('target_game_id', 0));
    $sourceGameId = max(0, cross_exam_int('source_game_id', 0));
    $limit = max(10, min(500, cross_exam_int('limit', 100)));
    $batchJobId = max(0, cross_exam_int('batch_job_id', 0));
    $notice = cross_exam_text('notice');
    $error = cross_exam_text('error');

    $model = null;
    if ($targetGameId > 0) {
        $model = $query->fetch($targetGameId, $sourceGameId, $limit);
    }

    catalog_head('Dependency Cross-Examine');
    echo <<<'CSS'
<style>
.cross-controls{display:grid;grid-template-columns:minmax(220px,1.2fr) minmax(220px,1.2fr) 120px auto;gap:10px;align-items:end}.cross-controls label{display:flex;flex-direction:column;gap:4px}.cross-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:12px 0}.cross-table{min-width:1280px}.cross-table td{vertical-align:top}.cross-coverage strong{display:block;margin-top:4px}.cross-coverage small{display:block;margin-top:4px}.cross-good{display:inline-flex;padding:3px 7px;border-radius:999px;font-size:11px;font-weight:700;color:#b8f3cb;background:rgba(67,190,110,.15)}.cross-warn{display:inline-flex;padding:3px 7px;border-radius:999px;font-size:11px;font-weight:700;color:#f5d98b;background:rgba(246,196,83,.13)}.cross-note{padding:10px 12px;border:1px solid var(--line2);border-radius:8px;background:rgba(255,255,255,.025);color:var(--muted);margin:0 0 12px}.cross-batch{display:flex;gap:10px;align-items:end;flex-wrap:wrap;padding:10px 12px;border:1px solid var(--line2);border-radius:8px;background:rgba(255,255,255,.025);margin:0 0 10px}.cross-batch label{display:flex;flex-direction:column;gap:4px;min-width:260px}.cross-batch-note{flex:1 1 340px;color:var(--muted);font-size:12px}.cross-select{width:42px;text-align:center}.cross-select input{width:18px;height:18px}.cross-file-name{display:inline-block;margin-top:2px}.cross-file-size{display:inline-block;margin-top:2px}.cross-game-engine{display:inline-block;margin-top:2px}.cross-target-files{display:inline-block;margin-top:3px}.cross-progress-overlay{position:fixed;inset:0;z-index:10000;display:none;align-items:center;justify-content:center;padding:24px;background:rgba(0,0,0,.68)}.cross-progress-overlay.is-open{display:flex}.cross-progress-dialog{width:min(720px,96vw);max-height:90vh;overflow:auto;border:1px solid var(--line2);border-radius:12px;background:var(--panel,#101827);box-shadow:0 24px 80px rgba(0,0,0,.5);padding:18px}.cross-progress-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px}.cross-progress-head h2{margin:0}.cross-progress-close{white-space:nowrap}.cross-progress-track{height:14px;border-radius:999px;overflow:hidden;background:rgba(255,255,255,.08);margin:16px 0 8px}.cross-progress-bar{height:100%;width:0%;background:linear-gradient(90deg,#3f69b8,#6695eb);transition:width .25s ease}.cross-progress-line{display:flex;justify-content:space-between;gap:14px;color:var(--muted);font-size:13px}.cross-progress-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin:14px 0}.cross-progress-stat{padding:9px;border:1px solid var(--line2);border-radius:8px;background:rgba(255,255,255,.025)}.cross-progress-stat strong{display:block;font-size:18px}.cross-progress-current,.cross-progress-message,.cross-progress-error{margin-top:10px;padding:10px 12px;border-radius:8px;background:rgba(255,255,255,.025)}.cross-progress-error{display:none;border:1px solid rgba(220,80,80,.4);color:#ffb6b6}.cross-progress-error.is-visible{display:block}.cross-progress-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:16px}@media(max-width:900px){.cross-controls{grid-template-columns:1fr 1fr}.cross-summary{grid-template-columns:1fr}.cross-batch{align-items:stretch}.cross-progress-stats{grid-template-columns:1fr 1fr}}
</style>
CSS;

    echo CatalogUi::pageHeader(
        'Dependency Cross-Examine',
        'Find packages already verified in sibling games that can satisfy actual missing dependencies in another game. Matching uses the same current compact required-path/export-path projections as normal dependency resolution.',
        [
            'Unverified files' => 'unverified-files.php',
            'Missing dependencies' => 'missing.php',
            'Background jobs' => 'background-jobs.php',
        ]
    );

    if ($notice !== '') {
        echo CatalogUi::alert('success', 'Background batch started', $notice);
    }
    if ($error !== '') {
        echo CatalogUi::alert('danger', 'Cross-game batch could not start', $error);
    }

    echo '<p class="cross-note"><strong>What counts as a candidate:</strong> the target game must currently have a dependency marked missing for the package/object. The selected same-engine source game must contain a verified package with the same package identity and its current <span class="mono">ue_export_lookup.path_hash</span> must exactly match the missing dependency\'s <span class="mono">required_path_hash</span>. Package-version/profile ranges are not used to hide cross-game providers.</p>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Compare games</h2><p>Choose the game whose missing dependencies you want to repair.</p></div></div><div class="ui-section__body">';
    echo '<form class="cross-controls" method="get">';
    echo '<label>Target game<select name="target_game_id"><option value="">Choose target game</option>';
    foreach ($games as $game) {
        echo '<option value="' . (int)$game['id'] . '"'
            . ($targetGameId === (int)$game['id'] ? ' selected' : '') . '>'
            . catalog_h((string)$game['name'] . ' / ' . (string)$game['engine_key']) . '</option>';
    }
    echo '</select></label>';

    $sourceGames = is_array($model['source_games'] ?? null) ? $model['source_games'] : [];
    echo '<label>Source game<select name="source_game_id"><option value="0">All same-engine games</option>';
    foreach ($sourceGames as $game) {
        echo '<option value="' . (int)$game['id'] . '"'
            . ($sourceGameId === (int)$game['id'] ? ' selected' : '') . '>'
            . catalog_h((string)$game['name']) . '</option>';
    }
    echo '</select></label>';
    echo '<label>Max results<select name="limit">';
    foreach ([25, 50, 100, 250, 500] as $value) {
        echo '<option value="' . $value . '"' . ($limit === $value ? ' selected' : '') . '>' . $value . '</option>';
    }
    echo '</select></label><div><button type="submit">Cross-examine</button></div></form></div></section>';

    if ($targetGameId < 1) {
        echo CatalogUi::emptyState('Choose a target game', 'The report will compare that game against other active games using the same engine profile family.');
        catalog_foot();
        exit;
    }

    $rows = is_array($model['rows'] ?? null) ? $model['rows'] : [];
    $target = is_array($model['target'] ?? null) ? $model['target'] : [];
    $diagnostics = is_array($model['diagnostics'] ?? null) ? $model['diagnostics'] : [];

    echo '<p class="cross-note"><strong>Scan input:</strong> '
        . number_format((int)($diagnostics['missing_dependency_rows'] ?? 0)) . ' actual missing dependency row(s) across '
        . number_format((int)($diagnostics['missing_packages'] ?? 0)) . ' package(s); '
        . number_format((int)($diagnostics['source_package_files'] ?? 0)) . ' verified source package file(s) had matching package names; '
        . number_format((int)($diagnostics['format2_source_files'] ?? 0)) . ' have current format-2 metadata/export projections; '
        . number_format((int)($diagnostics['exact_provider_files'] ?? 0)) . ' file(s) matched at least one missing object path.</p>';

    $exactTotal = 0;
    $ownerTotal = 0;
    foreach ($rows as $row) {
        $exactTotal += (int)($row['exact_object_matches'] ?? 0);
        $ownerTotal += (int)($row['exact_owner_count'] ?? 0);
    }
    echo '<div class="cross-summary">'
        . '<div class="stat"><h2>' . count($rows) . '</h2><p>Exact provider candidates</p></div>'
        . '<div class="stat"><h2>' . number_format($exactTotal) . '</h2><p>Exact missing-reference matches across candidates</p></div>'
        . '<div class="stat"><h2>' . number_format($ownerTotal) . '</h2><p>Referencing-file matches across candidates</p></div>'
        . '</div>';

    if ($rows === []) {
        echo CatalogUi::emptyState(
            'No exact sibling-game providers found',
            'The scan starts from the target game\'s actual missing dependency rows and uses the current compact export projection. The scan-input counts above show whether matching source package names and format-2 export data exist.'
        );
        catalog_foot();
        exit;
    }

    $destinationGames = array_values(array_filter(
        $games,
        static fn(array $game): bool => strcasecmp(
            trim((string)($game['engine_key'] ?? '')),
            trim((string)($target['engine_key'] ?? ''))
        ) === 0
    ));

    echo '<form method="post" action="dependency-cross-examine-action.php" id="cross-batch-form">'
        . '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('dependency-cross-examine')) . '">'
        . '<input type="hidden" name="report_target_game_id" value="' . $targetGameId . '">'
        . '<input type="hidden" name="source_game_id" value="' . $sourceGameId . '">'
        . '<input type="hidden" name="limit" value="' . $limit . '">';
    echo '<div class="cross-batch">'
        . '<label>Add selected packages to<select name="destination_game_id" required>';
    foreach ($destinationGames as $game) {
        $gameId = (int)$game['id'];
        echo '<option value="' . $gameId . '"' . ($gameId === $targetGameId ? ' selected' : '') . '>'
            . catalog_h((string)$game['name'] . ' / ' . (string)$game['engine_key']) . '</option>';
    }
    echo '</select></label>'
        . '<button type="submit" id="cross-queue-selected">Queue selected</button>'
        . '<div class="cross-batch-note">The current rows are evidence for <strong>' . catalog_h((string)($target['name'] ?? 'the report target'))
        . '</strong>. Clicking Queue selected now creates one lightweight parent job immediately. Revalidation and child import queue creation happen in the background.</div>'
        . '</div>';

    echo '<div class="table-wrap"><table class="cross-table"><thead><tr>'
        . '<th class="cross-select"><input type="checkbox" id="cross-select-all" aria-label="Select all rows"></th>'
        . '<th>Source game</th><th>Package / file</th><th>Identity</th><th>Detected</th>'
        . '<th>Target need</th><th>Exact coverage</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $sourceFileId = (int)$row['id'];
        $exact = (int)$row['exact_object_matches'];
        $missing = (int)$row['target_missing_count'];
        $owners = (int)$row['target_owner_count'];
        $exactOwners = (int)$row['exact_owner_count'];
        $coverage = number_format((float)$row['coverage_percent'], 1) . '%';
        $alreadyInTarget = !empty($row['already_in_target']);
        echo '<tr>';
        echo '<td class="cross-select"><input type="checkbox" name="source_file_ids[]" value="' . $sourceFileId
            . '" aria-label="Select ' . catalog_h((string)$row['original_name']) . '"></td>';
        echo '<td><strong>' . catalog_h((string)$row['source_game_name']) . '</strong><br><span class="muted cross-game-engine">'
            . catalog_h((string)$row['source_engine']) . '</span></td>';
        echo '<td><strong><a href="file-info.php?id=' . $sourceFileId . '">'
            . catalog_h((string)$row['package_name']) . '</a></strong><br><span class="cross-file-name">'
            . catalog_h((string)$row['original_name']) . '</span><br><small class="muted cross-file-size">'
            . catalog_h(catalog_bytes((int)$row['file_size'])) . '</small></td>';
        echo '<td><span class="mono small">GUID: ' . catalog_h((string)($row['package_guid'] ?? '')) . '</span><br>'
            . '<span class="mono small">MD5: ' . catalog_h((string)$row['md5']) . '</span><br>'
            . '<span class="mono small">SHA: ' . catalog_h((string)($row['sha1'] ?? '')) . '</span></td>';
        echo '<td class="mono">' . catalog_h((string)$row['detected_engine_key'])
            . ' v' . (int)$row['detected_package_version']
            . '<br><span class="muted">lic ' . (int)$row['detected_licensee_version'] . '</span></td>';
        echo '<td><strong>' . number_format($missing) . ' missing dependency reference'
            . ($missing === 1 ? '' : 's') . '</strong><br><span class="cross-target-files">'
            . number_format($owners) . ' referencing file' . ($owners === 1 ? '' : 's') . '</span></td>';
        echo '<td class="cross-coverage"><span class="cross-good">Exact object paths</span><strong>'
            . number_format($exact) . ' / ' . number_format($missing) . ' missing references (' . catalog_h($coverage) . ')</strong><small>'
            . number_format($exactOwners) . ' / ' . number_format($owners) . ' referencing file'
            . ($owners === 1 ? '' : 's') . ' receive at least one exact object match.</small>';
        if ($alreadyInTarget) {
            $existingId = (int)($row['target_existing_file_id'] ?? 0);
            echo '<small><span class="cross-warn">Already in report target</span> Identical MD5 is already verified as '
                . ($existingId > 0 ? '<a href="file-info.php?id=' . $existingId . '">file #' . $existingId . '</a>' : 'a target file')
                . '.</small>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div></form>';

    echo '<div class="cross-progress-overlay" id="cross-progress-overlay" aria-hidden="true">'
        . '<div class="cross-progress-dialog" role="dialog" aria-modal="true" aria-labelledby="cross-progress-title">'
        . '<div class="cross-progress-head"><div><h2 id="cross-progress-title">Queue selected packages</h2>'
        . '<div class="muted" id="cross-progress-status">Preparing background batch…</div></div>'
        . '<button type="button" class="cross-progress-close" id="cross-progress-close">Close</button></div>'
        . '<div class="cross-progress-track"><div class="cross-progress-bar" id="cross-progress-bar"></div></div>'
        . '<div class="cross-progress-line"><span id="cross-progress-count">0 / 0</span><span id="cross-progress-percent">0%</span></div>'
        . '<div class="cross-progress-stats">'
        . '<div class="cross-progress-stat"><strong id="cross-progress-queued">0</strong><span>Queued</span></div>'
        . '<div class="cross-progress-stat"><strong id="cross-progress-deduped">0</strong><span>Already queued</span></div>'
        . '<div class="cross-progress-stat"><strong id="cross-progress-skipped">0</strong><span>Skipped</span></div>'
        . '<div class="cross-progress-stat"><strong id="cross-progress-failed">0</strong><span>Failed</span></div>'
        . '</div>'
        . '<div class="cross-progress-line"><span>Elapsed: <strong id="cross-progress-elapsed">0s</strong></span>'
        . '<span>Estimated remaining: <strong id="cross-progress-eta">—</strong></span></div>'
        . '<div class="cross-progress-current"><strong>Current:</strong> <span id="cross-progress-current">Waiting for worker…</span></div>'
        . '<div class="cross-progress-message" id="cross-progress-message">Creating a lightweight background batch job.</div>'
        . '<div class="cross-progress-error" id="cross-progress-error"></div>'
        . '<div class="cross-progress-actions"><a class="button" href="background-jobs.php">Background jobs</a>'
        . '<button type="button" id="cross-progress-done">Close</button></div>'
        . '</div></div>';

    echo '<script>window.crossGameInitialBatchJobId=' . $batchJobId . ';</script>';
    echo <<<'JS'
<script>
(() => {
    const selectAll = document.getElementById('cross-select-all');
    const form = document.getElementById('cross-batch-form');
    const submitButton = document.getElementById('cross-queue-selected');
    const overlay = document.getElementById('cross-progress-overlay');
    const closeButton = document.getElementById('cross-progress-close');
    const doneButton = document.getElementById('cross-progress-done');
    let pollTimer = 0;
    let activeJobId = 0;

    const boxes = () => form ? Array.from(form.querySelectorAll('input[name="source_file_ids[]"]')) : [];
    const byId = (id) => document.getElementById(id);
    const setText = (id, value) => { const node = byId(id); if (node) node.textContent = String(value); };
    const formatSeconds = (value) => {
        if (value === null || value === undefined || value === '' || !Number.isFinite(Number(value))) return '—';
        let seconds = Math.max(0, Math.round(Number(value)));
        const hours = Math.floor(seconds / 3600); seconds %= 3600;
        const minutes = Math.floor(seconds / 60); seconds %= 60;
        if (hours > 0) return `${hours}h ${minutes}m ${seconds}s`;
        if (minutes > 0) return `${minutes}m ${seconds}s`;
        return `${seconds}s`;
    };
    const openModal = () => {
        if (!overlay) return;
        overlay.classList.add('is-open');
        overlay.setAttribute('aria-hidden', 'false');
    };
    const closeModal = () => {
        if (!overlay) return;
        overlay.classList.remove('is-open');
        overlay.setAttribute('aria-hidden', 'true');
    };
    const showError = (message) => {
        const node = byId('cross-progress-error');
        if (!node) return;
        node.textContent = message || '';
        node.classList.toggle('is-visible', Boolean(message));
    };
    const resetProgress = (selected = 0) => {
        setText('cross-progress-status', 'Submitting background batch…');
        setText('cross-progress-count', `0 / ${selected}`);
        setText('cross-progress-percent', '0%');
        setText('cross-progress-queued', 0);
        setText('cross-progress-deduped', 0);
        setText('cross-progress-skipped', 0);
        setText('cross-progress-failed', 0);
        setText('cross-progress-elapsed', '0s');
        setText('cross-progress-eta', '—');
        setText('cross-progress-current', 'Creating parent job…');
        setText('cross-progress-message', 'The browser request only creates one durable batch job. Package revalidation and import queue creation run in a worker.');
        const bar = byId('cross-progress-bar');
        if (bar) bar.style.width = '0%';
        showError('');
    };
    const updateProgress = (job) => {
        const progress = job && job.progress && typeof job.progress === 'object' ? job.progress : {};
        const result = job && job.result && typeof job.result === 'object' ? job.result : null;
        const done = Number(progress.done ?? result?.selected ?? 0) || 0;
        const total = Math.max(0, Number(progress.total ?? result?.selected ?? 0) || 0);
        const percent = Math.max(0, Math.min(100, Number(progress.percent ?? (job.status === 'completed' ? 100 : 0)) || 0));
        setText('cross-progress-status', `Job #${job.id} · ${job.status}`);
        setText('cross-progress-count', `${done} / ${total}`);
        setText('cross-progress-percent', `${percent}%`);
        setText('cross-progress-queued', progress.queued ?? result?.queued ?? 0);
        setText('cross-progress-deduped', progress.deduplicated ?? result?.deduplicated ?? 0);
        setText('cross-progress-skipped', progress.skipped ?? result?.skipped ?? 0);
        setText('cross-progress-failed', progress.failed ?? result?.failed ?? 0);
        setText('cross-progress-elapsed', formatSeconds(progress.elapsed_seconds ?? result?.elapsed_seconds ?? 0));
        setText('cross-progress-eta', job.status === 'completed' ? '0s' : formatSeconds(progress.eta_seconds));
        setText('cross-progress-current', progress.current_file || (job.status === 'queued' ? 'Waiting for an available worker…' : '—'));
        setText('cross-progress-message', progress.message || result?.message || 'Background batch is running.');
        const bar = byId('cross-progress-bar');
        if (bar) bar.style.width = `${percent}%`;
        if (job.last_error) showError(job.last_error);
    };
    const terminal = (status) => ['completed', 'dead_letter', 'cancelled', 'failed'].includes(String(status));
    const pollJob = async (jobId) => {
        activeJobId = Number(jobId) || 0;
        if (!activeJobId) return;
        try {
            const response = await fetch(`dependency-cross-examine-job.php?job_id=${encodeURIComponent(activeJobId)}&_=${Date.now()}`, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {'Accept': 'application/json'}
            });
            const data = await response.json();
            if (!response.ok || !data.ok || !data.job) throw new Error(data.error || 'Could not read batch progress.');
            updateProgress(data.job);
            if (terminal(data.job.status)) {
                pollTimer = 0;
                if (submitButton) submitButton.disabled = false;
                return;
            }
            pollTimer = window.setTimeout(() => pollJob(activeJobId), 1000);
        } catch (error) {
            showError(error instanceof Error ? error.message : 'Could not read batch progress.');
            pollTimer = window.setTimeout(() => pollJob(activeJobId), 2000);
        }
    };

    if (selectAll && form) {
        selectAll.addEventListener('change', () => {
            for (const box of boxes()) box.checked = selectAll.checked;
        });
        form.addEventListener('change', (event) => {
            if (!(event.target instanceof HTMLInputElement) || event.target.name !== 'source_file_ids[]') return;
            const all = boxes();
            selectAll.checked = all.length > 0 && all.every((box) => box.checked);
            selectAll.indeterminate = !selectAll.checked && all.some((box) => box.checked);
        });
        form.addEventListener('submit', async (event) => {
            const selected = boxes().filter((box) => box.checked);
            if (selected.length === 0) {
                event.preventDefault();
                window.alert('Select at least one package to queue.');
                return;
            }
            if (!window.fetch || !window.FormData) return;

            event.preventDefault();
            if (pollTimer) window.clearTimeout(pollTimer);
            resetProgress(selected.length);
            openModal();
            if (submitButton) submitButton.disabled = true;
            try {
                const body = new FormData(form);
                body.set('response', 'json');
                const response = await fetch(form.action, {
                    method: 'POST',
                    body,
                    credentials: 'same-origin',
                    headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}
                });
                const data = await response.json();
                if (!response.ok || !data.ok || !data.job_id) throw new Error(data.error || 'Could not start background batch.');
                setText('cross-progress-status', `Job #${data.job_id} · queued`);
                setText('cross-progress-message', `Background preparation started for ${data.selected} package(s) to ${data.destination_game}.`);
                pollJob(data.job_id);
            } catch (error) {
                if (submitButton) submitButton.disabled = false;
                showError(error instanceof Error ? error.message : 'Could not start background batch.');
                setText('cross-progress-status', 'Batch could not start');
            }
        });
    }

    closeButton?.addEventListener('click', closeModal);
    doneButton?.addEventListener('click', closeModal);
    overlay?.addEventListener('click', (event) => { if (event.target === overlay) closeModal(); });

    const initialJobId = Number(window.crossGameInitialBatchJobId || 0);
    if (initialJobId > 0) {
        resetProgress(0);
        openModal();
        pollJob(initialJobId);
    }
})();
</script>
JS;

    catalog_foot();
} catch (Throwable $error) {
    catalog_head('Dependency Cross-Examine Error');
    echo CatalogUi::alert('danger', 'Dependency Cross-Examine could not be loaded.', $error->getMessage());
    catalog_foot();
}
