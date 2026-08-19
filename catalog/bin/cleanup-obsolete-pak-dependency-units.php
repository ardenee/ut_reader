#!/usr/bin/env php
<?php
/**
 * Removes queued dependency:<file> units created by the retired PAK whole-game
 * dependency workflow. Targeted PAK refresh uses pak-dependency:<file> units and
 * does not consume these legacy rows.
 *
 * Default mode is read-only. Pass --execute to delete in bounded autocommit
 * batches. Running rows are never selected or deleted.
 */
declare(strict_types=1);

use UnrealDb\Catalog\Domain\Jobs\JobType;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/operational.php';

$execute = in_array('--execute', array_slice($argv, 1), true);
$batchSize = 5000;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--batch=(\d+)$/', (string)$argument, $match) === 1) {
        $batchSize = max(100, min(50000, (int)$match[1]));
    }
}

try {
    $application = catalog_operational_application();
    $db = $application->db;
    $queue = trim((string)($application->config['queue']['name'] ?? 'catalog')) ?: 'catalog';

    // Top-level PAK imports are a tiny operator set. parent_job_id IS NULL uses
    // the existing parent/status index boundary instead of scanning child rows.
    $pakStatement = $db->prepare(
        'SELECT id FROM ue_background_jobs '
        . 'WHERE queue_name=? AND parent_job_id IS NULL AND job_type=? ORDER BY id'
    );
    $pakStatement->execute([$queue, JobType::IMPORT_STAGED_PAK]);
    $pakJobIds = array_values(array_map('intval', $pakStatement->fetchAll(PDO::FETCH_COLUMN) ?: []));

    $coordinatorStatement = $db->prepare(
        'SELECT id FROM ue_background_jobs '
        . 'WHERE parent_job_id=? AND job_type=? AND workflow_unit_key="dependencies" LIMIT 1'
    );
    $countStatement = $db->prepare(
        'SELECT COUNT(*) FROM ue_background_jobs '
        . 'WHERE parent_job_id=? AND status="queued" AND job_type=? '
        . 'AND workflow_unit_key LIKE "dependency:%"'
    );
    $deleteStatement = $db->prepare(
        'DELETE FROM ue_background_jobs '
        . 'WHERE parent_job_id=? AND status="queued" AND job_type=? '
        . 'AND workflow_unit_key LIKE "dependency:%" '
        . 'ORDER BY id LIMIT ' . $batchSize
    );

    $coordinators = 0;
    $matching = 0;
    $deleted = 0;
    $batches = 0;

    foreach ($pakJobIds as $pakJobId) {
        if ($pakJobId < 1) {
            continue;
        }
        $coordinatorStatement->execute([$pakJobId, JobType::REBUILD_GAME_DEPENDENCIES]);
        $coordinatorJobId = (int)($coordinatorStatement->fetchColumn() ?: 0);
        if ($coordinatorJobId < 1) {
            continue;
        }
        $coordinators++;

        if (!$execute) {
            $countStatement->execute([$coordinatorJobId, JobType::REBUILD_FILE_DEPENDENCIES]);
            $matching += max(0, (int)$countStatement->fetchColumn());
            continue;
        }

        do {
            $deleteStatement->execute([$coordinatorJobId, JobType::REBUILD_FILE_DEPENDENCIES]);
            $removed = max(0, $deleteStatement->rowCount());
            if ($removed > 0) {
                $deleted += $removed;
                $batches++;
                fwrite(
                    STDERR,
                    'Removed ' . number_format($deleted) . ' obsolete queued PAK dependency unit(s)...' . PHP_EOL
                );
            }
        } while ($removed === $batchSize);
    }

    $result = [
        'ok' => true,
        'mode' => $execute ? 'execute' : 'dry_run',
        'queue' => $queue,
        'pak_jobs' => count($pakJobIds),
        'dependency_coordinators' => $coordinators,
        'matching_queued_units' => $execute ? null : $matching,
        'deleted_queued_units' => $execute ? $deleted : 0,
        'batches' => $batches,
        'batch_size' => $batchSize,
        'note' => $execute
            ? 'Only queued legacy dependency:<file> children were deleted; running jobs were untouched.'
            : 'Read-only. Re-run with --execute to remove the obsolete queued units in bounded batches.',
    ];
    fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . PHP_EOL);
    exit(2);
}
