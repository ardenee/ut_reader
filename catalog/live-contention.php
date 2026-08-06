<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

/** Return SQL without literal values so diagnostics remain safe to display. */
function live_contention_sql(mixed $value): string
{
    $sql = trim((string)$value);
    if ($sql === '') {
        return '';
    }
    $sql = preg_replace("/'(?:''|[^'])*'/", '?', $sql) ?? $sql;
    $sql = preg_replace('/\b(?:0x[0-9A-Fa-f]+|\d+(?:\.\d+)?)\b/', '?', $sql) ?? $sql;
    $sql = trim((string)(preg_replace('/\s+/', ' ', $sql) ?? $sql));
    return mb_substr($sql, 0, 1200, 'UTF-8');
}

/** @return list<array<string,mixed>> */
function live_contention_rows(PDO $db, string $sql, array $args = []): array
{
    $statement = $db->prepare($sql);
    $statement->execute($args);
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Live Contention')) {
        exit;
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $queueName = trim((string)($config['queue']['name'] ?? 'catalog')) ?: 'catalog';
    $connectionId = (int)($db->query('SELECT CONNECTION_ID()')->fetchColumn() ?: 0);

    $processes = live_contention_rows(
        $db,
        'SELECT ID,USER,HOST,DB,COMMAND,TIME,STATE,INFO '
        . 'FROM information_schema.PROCESSLIST '
        . 'WHERE ID<>? AND DB=DATABASE() AND COMMAND<>"Sleep" '
        . 'ORDER BY TIME DESC,ID ASC LIMIT 100',
        [$connectionId]
    );

    $transactions = [];
    $transactionError = '';
    try {
        $transactions = live_contention_rows(
            $db,
            'SELECT trx_mysql_thread_id,trx_state,'
            . 'TIMESTAMPDIFF(SECOND,trx_started,NOW()) age_seconds,'
            . 'trx_rows_locked,trx_rows_modified,trx_tables_locked,trx_query '
            . 'FROM information_schema.innodb_trx '
            . 'ORDER BY trx_started ASC LIMIT 100'
        );
    } catch (Throwable $error) {
        $transactionError = $error->getMessage();
    }

    $lockWaits = [];
    $lockWaitError = '';
    try {
        $lockWaits = live_contention_rows(
            $db,
            'SELECT waiting_pid,waiting_query_secs,waiting_query,'
            . 'blocking_pid,blocking_query,locked_table,locked_index '
            . 'FROM sys.innodb_lock_waits ORDER BY waiting_query_secs DESC LIMIT 100'
        );
    } catch (Throwable $error) {
        $lockWaitError = $error->getMessage();
    }

    $jobs = live_contention_rows(
        $db,
        'SELECT id,job_type,resource_class,resource_limit,concurrency_key,worker_id,'
        . 'TIMESTAMPDIFF(SECOND,leased_at,UTC_TIMESTAMP()) runtime_seconds,'
        . 'TIMESTAMPDIFF(SECOND,last_heartbeat_at,UTC_TIMESTAMP()) heartbeat_age_seconds,'
        . 'progress_json,last_error '
        . 'FROM ue_background_jobs WHERE queue_name=? AND status="running" '
        . 'ORDER BY leased_at ASC,id ASC LIMIT 100',
        [$queueName]
    );

    $longQueries = count(array_filter(
        $processes,
        static fn(array $row): bool => (int)($row['TIME'] ?? 0) >= 5
    ));
    $longTransactions = count(array_filter(
        $transactions,
        static fn(array $row): bool => (int)($row['age_seconds'] ?? 0) >= 5
    ));
    $affectedJobs = count(array_filter(
        $jobs,
        static fn(array $row): bool => (string)($row['job_type'] ?? '') === 'catalog.rebuild_affected_dependencies'
    ));

    catalog_head('Live Contention');
    echo CatalogUi::pageHeader(
        'Live Contention',
        'Read-only snapshot of active MySQL statements, InnoDB transactions, lock waits and background jobs. Refresh while the site is slow to identify the blocker.',
        ['Refresh' => 'live-contention.php', 'Workload Tracing' => 'workload-tracing.php', 'Background Jobs' => 'background-jobs.php']
    );

    echo '<div class="grid">';
    catalog_stat_card('Active DB statements', count($processes), $longQueries . ' running for at least 5 seconds', $longQueries > 0 ? 'attention' : 'good');
    catalog_stat_card('InnoDB transactions', count($transactions), $longTransactions . ' open for at least 5 seconds', $longTransactions > 0 ? 'attention' : 'good');
    catalog_stat_card('Current lock waits', count($lockWaits), count($lockWaits) > 0 ? 'Foreground requests may be blocked' : 'No lock waits observed', count($lockWaits) > 0 ? 'attention' : 'good');
    catalog_stat_card('Running queue jobs', count($jobs), $affectedJobs . ' affected-dependency job(s)', $affectedJobs > 1 ? 'attention' : 'good');
    echo '</div>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Active MySQL statements</h2>'
        . '<p>Sorted by current runtime. SQL literals are replaced with question marks.</p></div></div><div class="ui-section__body">';
    if ($processes === []) {
        echo CatalogUi::emptyState('No active catalogue statements', 'No non-sleeping statements were visible at the instant this page loaded.');
    } else {
        echo '<div class="table-wrap"><table><thead><tr><th>ID</th><th>Seconds</th><th>State</th><th>Connection</th><th>Normalized statement</th></tr></thead><tbody>';
        foreach ($processes as $row) {
            echo '<tr><td class="mono">' . (int)$row['ID'] . '</td><td>' . (int)$row['TIME'] . '</td>';
            echo '<td>' . catalog_h((string)($row['STATE'] ?? '')) . '</td>';
            echo '<td class="small">' . catalog_h((string)($row['USER'] ?? '')) . '<br><span class="muted">' . catalog_h((string)($row['HOST'] ?? '')) . '</span></td>';
            echo '<td class="mono small" style="white-space:normal;overflow-wrap:anywhere">' . catalog_h(live_contention_sql($row['INFO'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div></section>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>InnoDB lock waits</h2>'
        . '<p>A row here directly identifies a waiting connection and the connection blocking it.</p></div></div><div class="ui-section__body">';
    if ($lockWaits === []) {
        echo CatalogUi::emptyState('No current InnoDB lock waits', $lockWaitError !== '' ? $lockWaitError : 'No row lock waits were present at the instant this page loaded.');
    } else {
        echo '<div class="table-wrap"><table><thead><tr><th>Waiting PID</th><th>Seconds</th><th>Waiting statement</th><th>Blocking PID</th><th>Blocking statement</th><th>Table/index</th></tr></thead><tbody>';
        foreach ($lockWaits as $row) {
            echo '<tr><td class="mono">' . (int)($row['waiting_pid'] ?? 0) . '</td><td>' . (int)($row['waiting_query_secs'] ?? 0) . '</td>';
            echo '<td class="mono small" style="white-space:normal;overflow-wrap:anywhere">' . catalog_h(live_contention_sql($row['waiting_query'] ?? '')) . '</td>';
            echo '<td class="mono">' . (int)($row['blocking_pid'] ?? 0) . '</td>';
            echo '<td class="mono small" style="white-space:normal;overflow-wrap:anywhere">' . catalog_h(live_contention_sql($row['blocking_query'] ?? '')) . '</td>';
            echo '<td class="small">' . catalog_h((string)($row['locked_table'] ?? '')) . '<br><span class="muted">' . catalog_h((string)($row['locked_index'] ?? '')) . '</span></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div></section>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Open InnoDB transactions</h2>'
        . '<p>Long transactions retain row versions and may hold locks even when their current statement is not obvious.</p></div></div><div class="ui-section__body">';
    if ($transactions === []) {
        echo CatalogUi::emptyState('No open InnoDB transactions', $transactionError !== '' ? $transactionError : 'No transactions were open at the instant this page loaded.');
    } else {
        echo '<div class="table-wrap"><table><thead><tr><th>Thread</th><th>State</th><th>Age</th><th>Rows locked</th><th>Rows modified</th><th>Tables locked</th><th>Current statement</th></tr></thead><tbody>';
        foreach ($transactions as $row) {
            echo '<tr><td class="mono">' . (int)($row['trx_mysql_thread_id'] ?? 0) . '</td><td>' . catalog_h((string)($row['trx_state'] ?? '')) . '</td>';
            echo '<td>' . (int)($row['age_seconds'] ?? 0) . ' s</td><td>' . number_format((int)($row['trx_rows_locked'] ?? 0)) . '</td>';
            echo '<td>' . number_format((int)($row['trx_rows_modified'] ?? 0)) . '</td><td>' . number_format((int)($row['trx_tables_locked'] ?? 0)) . '</td>';
            echo '<td class="mono small" style="white-space:normal;overflow-wrap:anywhere">' . catalog_h(live_contention_sql($row['trx_query'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div></section>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Running background jobs</h2>'
        . '<p>More than one affected-dependency job for the same game is a contention warning. New jobs are serialized per game after this update.</p></div></div><div class="ui-section__body">';
    if ($jobs === []) {
        echo CatalogUi::emptyState('No background jobs are running', 'The queue had no leased rows at the instant this page loaded.');
    } else {
        echo '<div class="table-wrap"><table><thead><tr><th>Job</th><th>Type</th><th>Resource</th><th>Concurrency key</th><th>Runtime</th><th>Heartbeat age</th><th>Progress</th></tr></thead><tbody>';
        foreach ($jobs as $row) {
            $progress = json_decode((string)($row['progress_json'] ?? ''), true);
            $message = is_array($progress) ? trim((string)($progress['message'] ?? '')) : '';
            echo '<tr><td class="mono">#' . (int)$row['id'] . '</td><td class="mono small">' . catalog_h((string)$row['job_type']) . '</td>';
            echo '<td>' . catalog_h((string)$row['resource_class']) . ' (' . (int)$row['resource_limit'] . ')</td>';
            echo '<td class="mono small">' . catalog_h((string)($row['concurrency_key'] ?? '')) . '</td>';
            echo '<td>' . (int)($row['runtime_seconds'] ?? 0) . ' s</td><td>' . (int)($row['heartbeat_age_seconds'] ?? 0) . ' s</td>';
            echo '<td class="small">' . catalog_h($message !== '' ? $message : (string)($row['last_error'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div></section>';

    catalog_foot();
} catch (Throwable $error) {
    error_log('[UnrealDB live contention][' . catalog_request_id() . '] ' . $error->getMessage());
    if (!headers_sent()) {
        catalog_head('Live Contention Error');
    }
    echo CatalogUi::alert('danger', catalog_public_error_message(), 'Live contention could not be loaded.');
    catalog_foot();
}
