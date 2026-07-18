<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/bootstrap.php';

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
    exit(2);
}

$command = strtolower(trim((string)($argv[1] ?? '')));
if ($command === '') {
    job_control_usage();
}
$options = job_control_options(array_slice($argv, 2));
$application = catalog_bootstrap();
$queue = new PdoJobQueue($application->db);

try {
    if ($command === 'status') {
        $queueName = trim((string)($options['queue'] ?? 'catalog'));
        $limit = max(1, min((int)($options['limit'] ?? 50), 500));
        $statement = $application->db->prepare(
            'SELECT id,queue_name,job_type,status,priority,attempts,max_attempts,worker_id,available_at,leased_at,'
            . 'lease_expires_at,last_heartbeat_at,recovery_count,cancel_requested_at,cancel_reason,progress_json,last_error,created_at,completed_at,dead_lettered_at '
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
        $queueName = trim((string)($options['queue'] ?? 'catalog'));
        $result = $queue->recoverExpiredLeases($queueName);
        fwrite(STDOUT, json_encode(['queue' => $queueName] + $result, JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(0);
    }

    job_control_usage();
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
