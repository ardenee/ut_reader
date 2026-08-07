<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides the command-line utility for schedule maintenance.
 * Why: It handles administrator, migration, verification, repair, generation, or worker work that should not execute
 *      as an interactive browser request.
 * Role: CLI/maintenance entry point used from the server shell or operational scripts.
 * Audit: Operational entry point; verify scheduled/manual usage before considering removal.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/bootstrap.php';

use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

/** @return array<string,string> */
function maintenance_scheduler_options(array $arguments): array
{
    $options = [];
    foreach ($arguments as $argument) {
        $argument = (string)$argument;
        if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
            continue;
        }
        [$name, $value] = explode('=', substr($argument, 2), 2);
        $options[$name] = $value;
    }
    return $options;
}

function maintenance_scheduler_recent(PDO $db, string $queueName, string $jobType, int $seconds): bool
{
    $statement = $db->prepare(
        'SELECT id FROM ue_background_jobs '
        . 'WHERE queue_name=? AND job_type=? AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND) '
        . 'ORDER BY id DESC LIMIT 1'
    );
    $statement->execute([$queueName, $jobType, $seconds]);
    return $statement->fetchColumn() !== false;
}

function maintenance_scheduler_enqueue(
    PDO $db,
    PdoJobQueue $queue,
    string $queueName,
    string $jobType,
    array $payload,
    int $priority,
    string $dedupeKey,
    int $minimumInterval
): array {
    if (maintenance_scheduler_recent($db, $queueName, $jobType, $minimumInterval)) {
        return ['type' => $jobType, 'status' => 'recent'];
    }

    $jobId = $queue->enqueue(
        $queueName,
        $jobType,
        $payload,
        $priority,
        null,
        $dedupeKey,
        null,
        3
    );
    return ['type' => $jobType, 'status' => 'queued', 'job_id' => $jobId];
}

$options = maintenance_scheduler_options(array_slice($argv, 1));
$application = catalog_bootstrap();
$queueName = trim((string)($options['queue'] ?? ($application->config['queue']['name'] ?? 'catalog')));
$queue = new PdoJobQueue($application->db);

$reconcileInterval = max(900, min((int)($options['reconcile-interval'] ?? 3600), 86400));
$artifactInterval = max(900, min((int)($options['artifact-interval'] ?? 3600), 86400));
$progressInterval = max(900, min((int)($options['progress-interval'] ?? 21600), 604800));

try {
    $results = [];
    $results[] = maintenance_scheduler_enqueue(
        $application->db,
        $queue,
        $queueName,
        JobType::RECONCILE_UNVERIFIED_STORAGE,
        ['max_files' => 1000],
        25,
        'scheduled-reconcile-unverified',
        $reconcileInterval
    );
    $results[] = maintenance_scheduler_enqueue(
        $application->db,
        $queue,
        $queueName,
        JobType::PRUNE_STALE_ARTIFACTS,
        ['incoming_max_age_seconds' => 172800],
        200,
        'scheduled-prune-stale-artifacts',
        $artifactInterval
    );
    $results[] = maintenance_scheduler_enqueue(
        $application->db,
        $queue,
        $queueName,
        JobType::PRUNE_UPLOAD_PROGRESS,
        ['max_age_seconds' => 86400],
        200,
        'scheduled-prune-upload-progress',
        $progressInterval
    );

    fwrite(STDOUT, json_encode(['queue' => $queueName, 'results' => $results], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
