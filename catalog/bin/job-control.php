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
    fwrite(STDERR, "  php catalog/bin/job-control.php enqueue-rebuild-game --game-id=1 [--offset=0]\n");
    fwrite(STDERR, "  php catalog/bin/job-control.php enqueue-rebuild-file --file-id=123\n");
    fwrite(STDERR, "  php catalog/bin/job-control.php enqueue-rebuild-affected --file-id=123\n");
    fwrite(STDERR, "  php catalog/bin/job-control.php enqueue-source-identity-file --file-id=123\n");
    fwrite(STDERR, "  php catalog/bin/job-control.php enqueue-source-identity-game --game-id=1\n");
    fwrite(STDERR, "  php catalog/bin/job-control.php enqueue-clean-unverified-duplicates\n");
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
            . 'cancel_reason,progress_json,result_json,last_error,created_at,completed_at,dead_lettered_at '
            . 'FROM ue_background_jobs WHERE queue_name=? ORDER BY id DESC LIMIT ' . $limit
        );
        $statement->execute([$queueName]);
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            foreach (['progress_json' => 'progress', 'result_json' => 'result'] as $jsonField => $outputField) {
                if (!empty($row[$jsonField])) {
                    $decoded = json_decode((string)$row[$jsonField], true);
                    $row[$outputField] = is_array($decoded) ? $decoded : null;
                }
                unset($row[$jsonField]);
            }
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
        $offset = max(0, min((int)($options['offset'] ?? 0), 2000000000));
        if ($gameId < 1) {
            job_control_usage();
        }
        $jobId = $queue->enqueue(
            $queueName,
            JobType::REBUILD_GAME_DEPENDENCIES,
            ['game_id' => $gameId, 'offset' => $offset],
            20,
            null,
            'rebuild-game:' . $gameId . ':offset:' . $offset,
            null,
            3
        );
        fwrite(STDOUT, json_encode([
            'job_id' => $jobId,
            'queue' => $queueName,
            'type' => JobType::REBUILD_GAME_DEPENDENCIES,
            'offset' => $offset,
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(0);
    }

    if ($command === 'enqueue-rebuild-file' || $command === 'enqueue-rebuild-affected') {
        $fileId = (int)($options['file-id'] ?? 0);
        if ($fileId < 1) {
            job_control_usage();
        }
        $affected = $command === 'enqueue-rebuild-affected';
        $type = $affected ? JobType::REBUILD_AFFECTED_DEPENDENCIES : JobType::REBUILD_FILE_DEPENDENCIES;
        $jobId = $queue->enqueue(
            $queueName,
            $type,
            ['file_id' => $fileId],
            40,
            null,
            ($affected ? 'rebuild-affected-file:' : 'rebuild-file:') . $fileId,
            null,
            3
        );
        fwrite(STDOUT, json_encode(['job_id' => $jobId, 'queue' => $queueName, 'type' => $type], JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(0);
    }

    if ($command === 'enqueue-source-identity-file') {
        $fileId = (int)($options['file-id'] ?? 0);
        if ($fileId < 1) {
            job_control_usage();
        }
        $jobId = $queue->enqueue(
            $queueName,
            JobType::REPAIR_SOURCE_IDENTITY_FILE,
            ['file_id' => $fileId],
            10,
            null,
            'source-identity-file:' . $fileId,
            null,
            3
        );
        fwrite(STDOUT, json_encode(['job_id' => $jobId, 'queue' => $queueName, 'type' => JobType::REPAIR_SOURCE_IDENTITY_FILE], JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(0);
    }

    if ($command === 'enqueue-source-identity-game') {
        $gameId = (int)($options['game-id'] ?? 0);
        if ($gameId < 1) {
            job_control_usage();
        }
        $jobId = $queue->enqueue(
            $queueName,
            JobType::REPAIR_SOURCE_IDENTITY_GAME,
            ['game_id' => $gameId],
            10,
            null,
            'source-identity-game:' . $gameId,
            null,
            3
        );
        fwrite(STDOUT, json_encode(['job_id' => $jobId, 'queue' => $queueName, 'type' => JobType::REPAIR_SOURCE_IDENTITY_GAME], JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(0);
    }

    if ($command === 'enqueue-clean-unverified-duplicates') {
        $jobId = $queue->enqueue(
            $queueName,
            JobType::CLEAN_UNVERIFIED_DUPLICATES,
            [],
            15,
            null,
            'unverified-duplicate-cleanup',
            null,
            2
        );
        fwrite(STDOUT, json_encode([
            'job_id' => $jobId,
            'queue' => $queueName,
            'type' => JobType::CLEAN_UNVERIFIED_DUPLICATES,
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
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
