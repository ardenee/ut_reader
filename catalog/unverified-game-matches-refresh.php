<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Queues a full Upload Bucket exact game/dependency evidence refresh.
 * Why: Administrators need to rebuild cached suggestions after dependency data changes without slowing the page request.
 * Role: Thin POST action that queues durable background work and ensures the bucket worker is running.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDetachedWorker;
use UnrealDb\Catalog\Infrastructure\Unverified\CatalogUnverifiedGameMatchRefreshQueue;
use UnrealDb\Catalog\Infrastructure\Unverified\PdoUnverifiedGameMatchCache;

catalog_start_session();

try {
    if (!catalog_support_is_admin()) {
        throw new RuntimeException('Administrator login is required.');
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('POST is required.');
    }
    catalog_check_csrf('unverified-files');

    $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : 0;
    if ($userId < 1) {
        throw new RuntimeException('Administrator identity is unavailable.');
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $config = catalog_config();
    $db = catalog_db($config);
    if (!(new PdoUnverifiedGameMatchCache($db))->available()) {
        throw new RuntimeException(
            'Unverified game-match cache is not installed. Run php catalog/bin/migrate.php migrate first.'
        );
    }

    $queue = new CatalogUnverifiedGameMatchRefreshQueue($db, $config);
    $jobId = $queue->enqueueBucket($userId);

    $launcher = new CatalogDetachedWorker($config);
    $workerError = '';
    try {
        $status = $launcher->status($queue->queueName(), false);
        if (empty($status['active'])) {
            $launcher->start(
                $queue->queueName(),
                10000,
                $launcher->configuredWorkerCount()
            );
        }
    } catch (Throwable $error) {
        $workerError = trim($error->getMessage());
        error_log('[UnrealDB unverified match refresh] Could not start worker: ' . $workerError);
    }

    $query = [
        'match_refresh' => 'queued',
        'match_refresh_job' => $jobId,
    ];
    if ($workerError !== '') {
        $query['match_refresh_worker'] = 'manual';
    }
    header('Location: unverified-files.php?' . http_build_query($query), true, 303);
    exit;
} catch (Throwable $error) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    catalog_head('Refresh Upload Bucket Matches');
    echo CatalogUi::alert(
        'danger',
        'Upload Bucket match refresh could not be queued.',
        trim($error->getMessage()) ?: 'Unknown error.'
    );
    echo '<p><a class="button secondary" href="unverified-files.php">Back to Unverified Files</a></p>';
    catalog_foot();
}
