#!/usr/bin/env php
<?php
/**
 * Ledger-only one-shot backfill for invalid Unreal package System Errors.
 *
 * This command does not start workers, read package/archive bytes, extract
 * containers or retry jobs. It only inspects existing terminal job metadata and
 * records deterministic Unreal file-format failures in ue_system_errors.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoInvalidUeSystemErrorBackfill;

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $options = getopt('', ['queue::']);
    $requested = trim((string)($options['queue'] ?? ''));

    $queues = [];
    if ($requested !== '') {
        if (strlen($requested) > 80 || preg_match('/^[A-Za-z0-9._:-]+$/', $requested) !== 1) {
            throw new InvalidArgumentException('Invalid --queue value.');
        }
        $queues[] = $requested;
    } else {
        $statement = $db->prepare(
            'SELECT DISTINCT queue_name FROM ue_background_jobs '
            . 'WHERE job_type IN (?,?,?) AND ('
            . 'display_status="invalid_ue_package" OR status IN ("failed","dead_letter")'
            . ') ORDER BY queue_name'
        );
        $statement->execute([
            JobType::PROCESS_BUCKET_UPLOAD,
            JobType::PROCESS_BUCKET_STAGED_PACKAGE,
            JobType::IMPORT_STAGED_PACKAGE,
        ]);
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) ?: [] as $queue) {
            $queue = trim((string)$queue);
            if ($queue !== '') {
                $queues[] = $queue;
            }
        }
        if ($queues === []) {
            $fallbackQueue = trim((string)($config['queue']['name'] ?? 'catalog'));
            if ($fallbackQueue !== '') {
                $queues[] = $fallbackQueue;
            }
        }
    }

    $results = [];
    $totals = [
        'recorded' => 0,
        'historical_terminal_recorded' => 0,
        'provenance_normalized' => 0,
        'validation_records_normalized' => 0,
        'failed' => 0,
    ];
    foreach ($queues as $queue) {
        $result = (new PdoInvalidUeSystemErrorBackfill($db))->run($queue);
        $results[$queue] = $result;
        $totals['recorded'] += max(0, (int)($result['recorded'] ?? 0));
        $totals['historical_terminal_recorded'] += max(
            0,
            (int)($result['historical_terminal_recorded'] ?? 0)
        );
        $totals['provenance_normalized'] += max(
            0,
            (int)($result['provenance_normalized'] ?? 0)
        );
        $totals['validation_records_normalized'] += max(
            0,
            (int)($result['validation_records_normalized'] ?? 0)
        );
        $totals['failed'] += max(0, (int)($result['failed'] ?? 0));
    }

    fwrite(STDOUT, json_encode([
        'ok' => $totals['failed'] === 0,
        'mode' => 'ledger_only',
        'source_bytes_read' => false,
        'workers_started' => false,
        'queues' => $results,
        'totals' => $totals,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit($totals['failed'] === 0 ? 0 : 2);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode([
        'ok' => false,
        'error' => $error->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
