#!/usr/bin/env php
<?php
/**
 * Explicit compatibility maintenance for historical background-job rows.
 *
 * Normal worker startup and upload finalization are intentionally side-effect
 * free. Use this command only when an operator deliberately wants to repair
 * pre-upgrade durable queue metadata.
 *
 * No changes are made unless --execute is supplied.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Import\CatalogBucketBatchQueue;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogJobResourceLimitStore;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoArchiveParentLifecycleRepair;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoArchiveProfileMismatchOutcomeRepair;

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $options = getopt('', ['queue::', 'execute']);
    $execute = array_key_exists('execute', $options);
    $queue = trim((string)($options['queue'] ?? ($config['queue']['name'] ?? 'catalog')));
    if ($queue === '' || strlen($queue) > 80 || preg_match('/^[A-Za-z0-9._:-]+$/', $queue) !== 1) {
        throw new InvalidArgumentException('A valid --queue value is required.');
    }

    if (!$execute) {
        fwrite(STDOUT, json_encode([
            'ok' => true,
            'mode' => 'dry_run',
            'queue' => $queue,
            'changed' => false,
            'note' => 'No rows were changed. Re-run with --execute to apply historical compatibility repairs deliberately.',
            'operations' => [
                'synchronize_queued_resource_policy',
                'reclassify_historical_archive_member_outcomes',
                'reopen_legacy_completed_archive_parents_with_active_children',
                'migrate_legacy_bucket_redirect_queue_when_target_is_bucket_processing',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(0);
    }

    $resource = (new CatalogJobResourceLimitStore($db, $queue))->synchronizeQueuedPolicies();
    $outcomes = (new PdoArchiveProfileMismatchOutcomeRepair($db))->repair($queue);
    $parents = (new PdoArchiveParentLifecycleRepair($db))->reopenCompletedParentsWithActiveChildren($queue);

    $legacyMigrated = 0;
    $bucket = new CatalogBucketBatchQueue($db, $config);
    if ($queue === $bucket->queueName()) {
        $legacyMigrated = $bucket->migrateLegacyQueuedJobs();
    }

    fwrite(STDOUT, json_encode([
        'ok' => true,
        'mode' => 'execute',
        'queue' => $queue,
        'resource_policy' => $resource,
        'archive_outcome_repair' => $outcomes,
        'archive_parents_reopened' => $parents,
        'legacy_bucket_jobs_migrated' => $legacyMigrated,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode([
        'ok' => false,
        'error' => get_class($error) . ': ' . $error->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(2);
}
