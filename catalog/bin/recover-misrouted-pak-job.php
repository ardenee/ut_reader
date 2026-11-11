<?php
/**
 * Operator tool: inspect (default) or resolve a misrouted .pak import without
 * copying bytes or replaying its incorrect UnrealPak job.
 *
 * Example:
 *   php recover-misrouted-pak-job.php --job-id=9528900
 *   php recover-misrouted-pak-job.php --job-id=9528900 --actor-id=1 --apply
 *
 * --apply requires an existing administrator account. On DEV the optional
 * --storage-root can point to PRD storage via read-only UNC for preview.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}
require_once dirname(__DIR__) . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Jobs\CatalogMisroutedPakRecovery;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogQueueWorkerStarter;

$options = getopt('', ['job-id:', 'actor-id:', 'queue:', 'storage-root:', 'apply']);
$jobId = (int)($options['job-id'] ?? 0);
$queueName = trim((string)($options['queue'] ?? 'catalog'));
$apply = array_key_exists('apply', $options);
$actorId = (int)($options['actor-id'] ?? 0);
if ($jobId < 1 || !preg_match('/^[A-Za-z0-9._:-]{1,80}$/', $queueName)) {
    fwrite(STDERR, "Usage: php recover-misrouted-pak-job.php --job-id=N [--queue=catalog] [--actor-id=ADMIN --apply]\n");
    exit(2);
}
try {
    $config = catalog_config();
    $root = trim((string)($options['storage-root'] ?? ''));
    if ($root !== '') {
        $config['storage_path'] = $root;
    }
    $db = catalog_db($config);
    $service = new CatalogMisroutedPakRecovery($db, $config);
    $preview = $service->preview($jobId, $queueName);
    unset($preview['original_result'], $preview['staged_path']);
    echo json_encode(['preview' => $preview], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    if (!$apply) {
        echo "Read-only preview. Pass --actor-id=N --apply to resolve the failed job.\n";
        exit(0);
    }
    $actor = $actorId > 0
        ? catalog_one($db, 'SELECT id FROM ue_users WHERE id=? AND role="admin" LIMIT 1', [$actorId])
        : null;
    if (!$actor) {
        throw new RuntimeException('An existing admin actor ID is required for --apply.');
    }
    $result = $service->recover($jobId, $queueName, $actorId);
    if ((int)($result['new_job_id'] ?? 0) > 0) {
        $worker = (new CatalogQueueWorkerStarter($db, $config))->start($queueName, true, $actorId);
        $result['worker_error'] = $worker['worker_error'];
    }
    echo json_encode(['recovery' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'RECOVERY_FAILED: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
