<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/bootstrap.php';

use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

/** @return array<string,string> */
function job_control_options(array $arguments): array
{
    $options = [];
    for ($index = 0, $count = count($arguments); $index < $count; $index++) {
        $argument = (string)$arguments[$index];
        if (!str_starts_with($argument, '--')) {
            continue;
        }
        $argument = substr($argument, 2);
        if (str_contains($argument, '=')) {
            [$name, $value] = explode('=', $argument, 2);
            $options[$name] = $value;
            continue;
        }
        $next = $arguments[$index + 1] ?? null;
        if (is_string($next) && !str_starts_with($next, '--')) {
            $options[$argument] = $next;
            $index++;
        } else {
            $options[$argument] = '1';
        }
    }
    return $options;
}

function job_control_usage(): never
{
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php catalog/bin/job-control.php status [--queue=catalog] [--limit=50]\n");
    fwrite(STDERR, "  php catalog/bin/job-control.php cancel --id=123 [--reason=message]\n");
    fwrite(STDERR, "  php catalog/bin/job-control.php retry --id=123\n");
    fwrite(STDERR, "  php catalog/bin/job-control.php recover [--queue=catalog]\n");
    fwrite(STDERR, "  php catalog/bin/job-control.php enqueue-rebuild-game --game-id=1\n");
    fwrite(STDERR, "  php catalog/bin/job-control.php enqueue-rebuild-file --file-id=123\n");
    fwrite(STDERR, "  php catalog/bin/job-control.php enqueue-prune [--max-age-seconds=86400]\n");
    exit(2);
}

$command = strtolower(trim((string)($argv[1] ?? '')));
if ($command === '') {
    job_control_usage();
}
$options = job_control_options(array_slice($argv, 2));
$application = catalog_bootstrap();
$queue = new PdoJobQueue($application->db);
$queueName = trim((string)($options['queue'] ?? ($application->config['queue']['name'] ?? 'catalog')));

try {
    if ($command === 'status') {
        $limit = max(1, min((int)($options['limit'] ?? 50), 500));
        $statement = $application->db->prepare(
            'SELECT id,queue_name,job_type,resource_class,resource_limit,concurrency_key,status,priority,attempts,max_attempts,'
            . 'worker_id,available_at,leased_at,lease_expires_at,last_heartbeat_at,recovery_count,cancel_requested_at,'
            . 'cancel_reason,progress_json,last_error,created_at,completed_at,dead_lettered_at '
            . 'FROM ue_background_jobs WHERE queue_name=? ORDER BY id DESC LIMIT ' . $limit
        );
        $statement->execute([$queueName]);
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($row['progress_json'])) {
                $progress = json_decode((string)$row['progress_json'], true);
                $row['progress'] = is_array($progress) ? $progress : null;
            }
            unset($row['progress_json']);
            fwrite(STDOUT, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
        }
        exit(0);
    }

    if ($command === 'cancel') {
        $jobId = (int)($options['id'] ?? 0);
        if ($jobId < 1) {
            job_control_usage();
        }
        $result = $queue->requestCancellation($jobId, null, (string)($options['reason'] ?? 'Cancelled by operator.'));
        fwrite(STDOUT, json_encode(['job_id' => $jobId, 'status' => $result], JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(in_array($result, ['cancelled', 'cancel_requested'], true) ? 0 : 1);
    }

    if ($command === 'retry') {
        $jobId = (int)($options['id'] ?? 0);
        if ($jobId < 1) {
            job_control_usage();
        }
        $retried = $queue->retryDeadLetter($jobId);
        fwrite(STDOUT, json_encode(['job_id' => $jobId, 'retried' => $retried], JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit($retried ? 0 : 1);
    }

    if ($command === 'recover') {
        $result = $queue->recoverExpiredLeases($queueName);
        fwrite(STDOUT, json_encode(['queue' => $queueName] + $result, JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(0);
    }

    if ($command === 'enqueue-rebuild-game') {
        $gameId = (int)($options['game-id'] ?? 0);
        if ($gameId < 1) {
            job_control_usage();
        }
        $jobId = $queue->enqueue(
            $queueName,
            JobType::REBUILD_GAME_DEPENDENCIES,
            ['game_id' => $gameId],
            20,
            null,
            'rebuild-game:' . $gameId,
            null,
            3
        );
        fwrite(STDOUT, json_encode(['job_id' => $jobId, 'queue' => $queueName, 'type' => JobType::REBUILD_GAME_DEPENDENCIES], JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(0);
    }

    if ($command === 'enqueue-rebuild-file') {
        $fileId = (int)($options['file-id'] ?? 0);
        if ($fileId < 1) {
            job_control_usage();
        }
        $jobId = $queue->enqueue(
            $queueName,
            JobType::REBUILD_AFFECTED_DEPENDENCIES,
            ['file_id' => $fileId],
            40,
            null,
            'rebuild-file:' . $fileId,
            null,
            3
        );
        fwrite(STDOUT, json_encode(['job_id' => $jobId, 'queue' => $queueName, 'type' => JobType::REBUILD_AFFECTED_DEPENDENCIES], JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(0);
    }

    if ($command === 'enqueue-prune') {
        $maxAge = max(60, min((int)($options['max-age-seconds'] ?? 86400), 604800));
        $jobId = $queue->enqueue(
            $queueName,
            JobType::PRUNE_UPLOAD_PROGRESS,
            ['max_age_seconds' => $maxAge],
            200,
            null,
            'prune-upload-progress',
            null,
            2
        );
        fwrite(STDOUT, json_encode(['job_id' => $jobId, 'queue' => $queueName, 'type' => JobType::PRUNE_UPLOAD_PROGRESS], JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(0);
    }

    job_control_usage();
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
