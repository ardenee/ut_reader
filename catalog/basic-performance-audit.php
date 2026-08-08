<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders the Basic Page Performance Audit and runs browser-side authenticated GET measurements.
 * Why: Request-resource telemetry schema knowledge and route matching now belong to an Infrastructure read model.
 * Role: Presentation adapter only.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Persistence\PdoBasicPerformanceAuditQuery;

/** @return list<array{id:string,label:string,href:string,route_suffix:string,method:string,target_ms:int,run:bool}> */
function basic_performance_targets(): array
{
    return [
        ['id' => 'dashboard', 'label' => 'Dashboard', 'href' => 'dashboard.php', 'route_suffix' => '/catalog/dashboard.php', 'method' => 'GET', 'target_ms' => 1000, 'run' => true],
        ['id' => 'games', 'label' => 'Games', 'href' => 'games.php', 'route_suffix' => '/catalog/games.php', 'method' => 'GET', 'target_ms' => 1000, 'run' => true],
        ['id' => 'library', 'label' => 'Library', 'href' => 'library.php', 'route_suffix' => '/catalog/library.php', 'method' => 'GET', 'target_ms' => 1500, 'run' => true],
        ['id' => 'game-manager', 'label' => 'Game Manager', 'href' => 'game-manager.php', 'route_suffix' => '/catalog/game-manager.php', 'method' => 'GET', 'target_ms' => 1500, 'run' => true],
        ['id' => 'unverified-files', 'label' => 'Unverified Files', 'href' => 'unverified-files.php', 'route_suffix' => '/catalog/unverified-files.php', 'method' => 'GET', 'target_ms' => 1500, 'run' => true],
        ['id' => 'background-jobs', 'label' => 'Background Jobs', 'href' => 'background-jobs.php', 'route_suffix' => '/catalog/background-jobs.php', 'method' => 'GET', 'target_ms' => 1500, 'run' => true],
        ['id' => 'search', 'label' => 'Search form', 'href' => 'index.php?page=search', 'route_suffix' => '/catalog/index.php?page=search', 'method' => 'GET', 'target_ms' => 1000, 'run' => true],
        ['id' => 'missing-counts', 'label' => 'Game missing-count API', 'href' => 'api/v1/game-missing-counts.php', 'route_suffix' => '/catalog/api/v1/game-missing-counts.php', 'method' => 'GET', 'target_ms' => 1000, 'run' => true],
        ['id' => 'login', 'label' => 'Login submission', 'href' => 'index.php?page=login', 'route_suffix' => '/catalog/index.php?page=login', 'method' => 'POST', 'target_ms' => 1500, 'run' => false],
    ];
}

/** @param array<string,mixed>|null $row */
function basic_performance_assessment(?array $row, int $targetMs): array
{
    if ($row === null) {
        return ['unmeasured', 'neutral', 'No recorded sample'];
    }

    $samples = max(1, (int)($row['sample_count'] ?? 0));
    $averageMs = ((int)($row['total_duration_us'] ?? 0) / $samples) / 1000;
    $lastMs = ((int)($row['last_duration_us'] ?? 0)) / 1000;
    $status = (int)($row['last_status'] ?? 0);

    if ($status >= 400 || $lastMs > 10000 || $averageMs > 10000) {
        return ['fail', 'danger', 'More than 10 seconds or an HTTP error'];
    }
    if ($lastMs <= $targetMs && $averageMs <= $targetMs * 2) {
        return ['pass', 'success', 'Within target'];
    }
    if ($lastMs <= 3000 && $averageMs <= 3000) {
        return ['warning', 'warning', 'Above target but below 3 seconds'];
    }
    return ['fail', 'danger', 'More than 3 seconds'];
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Basic Page Performance Audit')) {
        exit;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $targets = basic_performance_targets();
    $queryResult = (new PdoBasicPerformanceAuditQuery($db))->metrics($targets);
    $metrics = $queryResult['metrics'];
    $traceError = $queryResult['error'];

    catalog_head('Basic Page Performance Audit');
    echo CatalogUi::pageHeader(
        'Basic Page Performance Audit',
        'Measure the ordinary pages used after login and compare browser wall time with recorded server, SQL and CPU time.',
        ['Performance Readiness' => 'performance-readiness.php', 'Workload Tracing' => 'workload-tracing.php', 'Dashboard' => 'dashboard.php']
    );

    echo '<style>';
    echo '.basic-audit-controls{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0 0 12px}.basic-audit-live{font-variant-numeric:tabular-nums}.basic-audit-row.is-running{outline:1px solid var(--blue);outline-offset:-1px}.basic-audit-status{min-width:92px}.basic-audit-note{max-width:330px}.basic-audit-progress{margin-left:8px}.basic-audit-table td,.basic-audit-table th{white-space:nowrap}.basic-audit-table td.basic-audit-note{white-space:normal}';
    echo '</style>';

    echo '<div class="card"><h2>Run the basic GET audit</h2>';
    echo '<p>The audit opens each page through authenticated same-origin GET requests, one at a time. It does not submit forms or modify catalogue data. A request is stopped after 15 seconds and marked failed.</p>';
    echo '<div class="basic-audit-controls"><button type="button" id="basic-audit-run">Run audit</button><button type="button" id="basic-audit-stop" class="button secondary" disabled>Stop</button><span id="basic-audit-progress" class="muted basic-audit-progress">Not running.</span></div>';
    echo '<p class="muted small">Normal target: 1–1.5 seconds depending on the page. Warning: over target but no more than 3 seconds. Failure: over 3 seconds; severe failure: over 10 seconds or timeout.</p></div>';

    if ($traceError !== '') {
        echo CatalogUi::alert('warning', 'Recorded route telemetry is unavailable.', $traceError);
    }

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Core page results</h2><p>Recorded values come from normal site usage. Live values are filled by the Run audit button.</p></div></div><div class="ui-section__body">';
    echo '<div class="ui-table-region"><table class="basic-audit-table"><thead><tr><th>Page</th><th>Method</th><th>Target</th><th>Recorded status</th><th>Recorded average</th><th>Recorded last</th><th>Recorded maximum</th><th>Average SQL</th><th>Average CPU</th><th>Queries</th><th>Samples</th><th>Live wall</th><th>Live server</th><th>Live SQL</th><th>Live CPU</th><th>Live queries</th><th>Notes</th></tr></thead><tbody>';

    $liveTargets = [];
    foreach ($targets as $target) {
        $row = $metrics[$target['id']] ?? null;
        [$statusLabel, $statusTone, $statusNote] = basic_performance_assessment($row, $target['target_ms']);
        $samples = $row ? max(1, (int)$row['sample_count']) : 0;
        $averageMs = $row ? ((int)$row['total_duration_us'] / $samples) / 1000 : null;
        $averageSqlMs = $row ? ((int)$row['total_sql_us'] / $samples) / 1000 : null;
        $averageCpuMs = $row ? ((int)$row['total_cpu_us'] / $samples) / 1000 : null;
        $lastMs = $row ? ((int)$row['last_duration_us']) / 1000 : null;
        $maximumMs = $row ? ((int)$row['max_duration_us']) / 1000 : null;

        echo '<tr id="basic-audit-' . catalog_h($target['id']) . '" class="basic-audit-row" data-audit-id="' . catalog_h($target['id']) . '">';
        echo '<td><a href="' . catalog_h($target['href']) . '">' . catalog_h($target['label']) . '</a></td>';
        echo '<td>' . catalog_h($target['method']) . '</td><td>' . number_format($target['target_ms']) . ' ms</td>';
        echo '<td class="basic-audit-status">' . CatalogUi::badge($statusLabel, $statusTone) . '</td>';
        echo '<td>' . ($averageMs === null ? '—' : number_format($averageMs, 2) . ' ms') . '</td>';
        echo '<td>' . ($lastMs === null ? '—' : number_format($lastMs, 2) . ' ms') . '</td>';
        echo '<td>' . ($maximumMs === null ? '—' : number_format($maximumMs, 2) . ' ms') . '</td>';
        echo '<td>' . ($averageSqlMs === null ? '—' : number_format($averageSqlMs, 2) . ' ms') . '</td>';
        echo '<td>' . ($averageCpuMs === null ? '—' : number_format($averageCpuMs, 2) . ' ms') . '</td>';
        echo '<td>' . ($row ? number_format((int)$row['last_query_count']) : '—') . '</td><td>' . ($row ? number_format($samples) : '0') . '</td>';
        echo '<td class="basic-audit-live" data-live-wall>—</td><td class="basic-audit-live" data-live-server>—</td><td class="basic-audit-live" data-live-sql>—</td><td class="basic-audit-live" data-live-cpu>—</td><td class="basic-audit-live" data-live-queries>—</td>';
        echo '<td class="basic-audit-note" data-live-note>' . catalog_h($target['run'] ? $statusNote : 'Measured during a real login; not submitted by this audit.') . '</td></tr>';

        if ($target['run']) {
            $liveTargets[] = [
                'id' => $target['id'],
                'label' => $target['label'],
                'href' => $target['href'],
                'targetMs' => $target['target_ms'],
            ];
        }
    }
    echo '</tbody></table></div></div></section>';

    echo '<div class="card"><h2>How to read a slow result</h2><p><strong>Wall time high, SQL low, CPU low:</strong> session/file lock, network wait, filesystem wait or another blocking dependency. <strong>SQL time high:</strong> database query or lock problem. <strong>CPU time high:</strong> PHP processing problem. <strong>All values low but browser wall high:</strong> response transfer or browser-side processing.</p>';
    echo '<p>Use <a href="workload-tracing.php">Workload Tracing</a> for statement digests and resource details. Use <a href="performance-readiness.php">Performance Readiness</a> for historical route and exact-count telemetry.</p></div>';

    $targetsJson = json_encode($liveTargets, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    echo '<script>window.UnrealDbBasicAuditTargets=' . $targetsJson . ';</script>';
    echo <<<'JS'
<script>
(function () {
    'use strict';

    var runButton = document.getElementById('basic-audit-run');
    var stopButton = document.getElementById('basic-audit-stop');
    var progress = document.getElementById('basic-audit-progress');
    var targets = Array.isArray(window.UnrealDbBasicAuditTargets) ? window.UnrealDbBasicAuditTargets : [];
    var stopped = false;
    var activeController = null;

    function metric(serverTiming, name) {
        var expression = new RegExp('(?:^|,)\\s*' + name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '(?:;[^,]*)?;dur=([0-9.]+)', 'i');
        var match = String(serverTiming || '').match(expression);
        return match ? Number(match[1]) : null;
    }

    function text(cell, value) {
        if (cell) cell.textContent = value;
    }

    function tone(row, result, elapsed, target) {
        row.classList.remove('is-running');
        var statusCell = row.querySelector('.basic-audit-status');
        if (!statusCell) return;
        var label = 'pass';
        var className = 'success';
        if (!result.ok || elapsed > 3000) {
            label = 'fail';
            className = 'danger';
        } else if (elapsed > target) {
            label = 'warning';
            className = 'warning';
        }
        statusCell.innerHTML = '<span class="ui-badge ui-badge--' + className + '">' + label + '</span>';
    }

    async function runTarget(target, index) {
        var row = document.querySelector('[data-audit-id="' + target.id + '"]');
        if (!row) return;
        row.classList.add('is-running');
        text(row.querySelector('[data-live-note]'), 'Running…');
        progress.textContent = 'Running ' + (index + 1) + ' of ' + targets.length + ': ' + target.label;

        activeController = new AbortController();
        var timer = window.setTimeout(function () { activeController.abort(); }, 15000);
        var started = performance.now();
        var result = { ok: false };
        try {
            var url = new URL(target.href, window.location.href);
            url.searchParams.set('__performance_audit', String(Date.now()));
            var response = await fetch(url.pathname + url.search, {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                signal: activeController.signal,
                headers: { 'X-UnrealDB-Performance-Audit': '1' }
            });
            await response.text();
            var elapsed = performance.now() - started;
            var serverTiming = response.headers.get('Server-Timing') || '';
            var app = metric(serverTiming, 'app');
            var db = metric(serverTiming, 'db');
            var cpu = Number(response.headers.get('X-UnrealDB-CPU-ms'));
            var queries = response.headers.get('X-UnrealDB-Query-Count');
            result = { ok: response.ok, elapsed: elapsed };

            text(row.querySelector('[data-live-wall]'), elapsed.toFixed(2) + ' ms');
            text(row.querySelector('[data-live-server]'), app === null ? '—' : app.toFixed(2) + ' ms');
            text(row.querySelector('[data-live-sql]'), db === null ? '—' : db.toFixed(2) + ' ms');
            text(row.querySelector('[data-live-cpu]'), Number.isFinite(cpu) ? cpu.toFixed(2) + ' ms' : '—');
            text(row.querySelector('[data-live-queries]'), queries || '—');
            text(row.querySelector('[data-live-note]'), response.ok ? ('HTTP ' + response.status) : ('HTTP ' + response.status + ' failure'));
            tone(row, result, elapsed, target.targetMs);
        } catch (error) {
            var elapsed = performance.now() - started;
            text(row.querySelector('[data-live-wall]'), elapsed.toFixed(2) + ' ms');
            text(row.querySelector('[data-live-note]'), error && error.name === 'AbortError' ? 'Timed out after 15 seconds' : String(error));
            tone(row, result, elapsed, target.targetMs);
        } finally {
            window.clearTimeout(timer);
            activeController = null;
            row.classList.remove('is-running');
        }
    }

    runButton.addEventListener('click', async function () {
        if (!targets.length) return;
        stopped = false;
        runButton.disabled = true;
        stopButton.disabled = false;
        for (var index = 0; index < targets.length; index++) {
            if (stopped) break;
            await runTarget(targets[index], index);
        }
        progress.textContent = stopped ? 'Audit stopped.' : 'Audit complete. Reload this page to include the new server telemetry samples.';
        runButton.disabled = false;
        stopButton.disabled = true;
    });

    stopButton.addEventListener('click', function () {
        stopped = true;
        if (activeController) activeController.abort();
        stopButton.disabled = true;
    });
}());
</script>
JS;

    catalog_foot();
} catch (Throwable $error) {
    error_log('[UnrealDB][' . catalog_request_id() . '] basic performance audit failed: ' . $error->getMessage());
    if (!headers_sent()) {
        catalog_head('Basic Page Performance Audit Error');
    }
    echo CatalogUi::alert('danger', 'The basic page performance audit could not be loaded.', catalog_public_error_message());
    catalog_foot();
}
